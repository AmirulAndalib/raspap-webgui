<?php

/**
 * Dashboard UI class
 *
 * @description A class for rendering the RaspAP dashboard
 * @author      Bill Zimmerman <billzimmerman@gmail.com>
 * @license     https://github.com/raspap/raspap-webgui/blob/master/LICENSE
 */

namespace RaspAP\UI;

class Dashboard {

    private string $firewallConfig;

    public function __construct() {
        $this->firewallConfig = RASPI_CONFIG.'/networking/firewall.conf';
    }

    /*
     * Returns the management page for an associated VPN
     *
     * @param string $interface
     * @return string
     */
    public function getVpnManged(?string $interface = null): ?string
    {
        return match ($interface) {
            'wg0' => '/wg_conf',
            'tun0' => '/openvpn_conf',
            'tailscale0' => '/plugin__Tailscale',
            default => null,
        };
    }

    /*
     * Parses output of iw, extracts frequency (MHz) and classifies
     * it as 2.4 or 5 GHz. Returns null if not found
     *
     * @param string $interface
     * @return string frequency
     */
    public function getFrequencyBand(string $interface): ?string
    {
        $output = shell_exec("iw dev " . escapeshellarg($interface) . " info 2>/dev/null");
        if (!$output) {
            return null;
        }

        if (preg_match('/channel\s+\d+\s+\((\d+)\s+MHz\)/', $output, $matches)) {
            $frequency = (int)$matches[1];

            if ($frequency >= 2400 && $frequency < 2500) {
                return "2.4";
            } elseif ($frequency >= 5000 && $frequency < 6000) {
                return "5";
            }
        }
        return null;
    }

    /*
     * Aggregate function that fetches output of ip and calls
     * functions to parse output into discreet network properties
     *
     * @param string $interface
     * @return array
     */
    public function getInterfaceDetails(string $interface): array
    {
        $output = shell_exec('ip a show ' . escapeshellarg($interface));
        if (!$output) {
            return [
                'mac' => _('No MAC Address Found'),
                'ipv4' => 'None',
                'ipv4_netmask' => '-',
                'ipv6' => _('No IPv6 Address Found'),
                'state' => 'unknown'
            ];
        }
        $cleanOutput = preg_replace('/\s\s+/', ' ', implode(' ', explode("\n", $output)));

        return [
            'mac' => $this->getMacAddress($cleanOutput),
            'ipv4' => $this->getIPv4Addresses($cleanOutput),
            'ipv4_netmask' => $this->getIPv4Netmasks($cleanOutput),
            'ipv6' => $this->getIPv6Addresses($cleanOutput),
            'state' => $this->getInterfaceState($cleanOutput),
        ];
    }

    private function getMacAddress(string $output): string
    {
        return preg_match('/link\/ether ([0-9a-f:]+)/i', $output, $matches) ? $matches[1] : _('No MAC Address Found');
    }

    private function getIPv4Addresses(string $output): string
    {
        if (!preg_match_all('/inet (\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})\/([0-3][0-9])/i', $output, $matches, PREG_SET_ORDER)) {
            return 'None';
        }

        $addresses = array_column($matches, 1);
        return implode(' ', $addresses);
    }

    private function getIPv4Netmasks(string $output): string
    {
        if (!preg_match_all('/inet (\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})\/([0-3][0-9])/i', $output, $matches, PREG_SET_ORDER)) {
            return '-';
        }

        $netmasks = array_map(fn($match) => long2ip(-1 << (32 - (int)$match[2])), $matches);
        return implode(' ', $netmasks);
    }

    private function getIPv6Addresses(string $output): string
    {
        return preg_match_all('/inet6 ([a-f0-9:]+)/i', $output, $matches) && isset($matches[1])
            ? implode(' ', $matches[1])
            : _('No IPv6 Address Found');
    }

    private function getInterfaceState(string $output): string
    {
        return preg_match('/state (UP|DOWN)/i', $output, $matches) ? $matches[1] : 'unknown';
    }

    public function getWirelessDetails(string $interface): array
    {
        $output = shell_exec('iw dev ' . escapeshellarg($interface) . ' info');
        if (!$output) {
            return ['bssid' => '-', 'ssid' => '-'];
        }
        $cleanOutput = preg_replace('/\s\s+/', ' ', trim($output)); // Fix here

        return [
            'bssid' => $this->getConnectedBSSID($cleanOutput),
            'ssid' => $this->getSSID($cleanOutput),
        ];
    }

    private function getConnectedBSSID(string $output): string
    {
        return preg_match('/Connected to (([0-9A-Fa-f]{2}:){5}([0-9A-Fa-f]{2}))/i', $output, $matches)
            ? $matches[1]
            : '-';
    }

    private function getSSID(string $output): string
    {
        return preg_match('/ssid ([^\n\s]+)/i', $output, $matches)
            ? $matches[1]
            : '-';
    }

    /*
     * Parses the output of iw to obtain a list of wireless clients
     *
     * @return integer $clientCount
     */
    public function getWirelessClients()
    {
        exec('iw dev wlan0 station dump', $output, $status);

        if ($status !== 0) {
            return 0;
        }
        // enumerate 'station' entries (each represents a wireless client)
        $clientCount = 0;
        foreach ($output as $line) {
            if (strpos($line, 'Station') === 0) {
                $clientCount++;
            }
        }
        return $clientCount;
    }

    /*
     * Retrieves ethernet neighbors from ARP cache, parses DHCP leases 
     * to find matching MAC addresses and returns only clients that
     * exist in both sources
     *
     * @return int $clients
     */
    public function getEthernetClients(): int
    {
        $arpOutput = shell_exec("ip neigh show | awk '{print $5}' | sort -u");
        $arpMacs = array_filter(explode("\n", trim($arpOutput)));

        $leaseFile = RASPI_DNSMASQ_LEASES;
        $dhcpMacs = [];

        if (file_exists($leaseFile)) {
            $leases = file($leaseFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($leases as $lease) {
                $parts = explode(' ', $lease);
                if (isset($parts[1])) {
                    $dhcpMacs[] = $parts[1]; // MAC address from DHCP leases
                }
            }
        }
        // filter ARP results to include only DHCP clients
        $clients = array_intersect($arpMacs, $dhcpMacs);
        return count($clients);
    }

    public function formatClientLabel($clientCount)
    {
        return ngettext('client', 'clients', $clientCount);
    }

    /*
     * Determines the device's primary connection type by
     * parsing the output of ip route; the interface listed
     * as the default gateway is used for internet connectivity.
     * 
     * The following interface classifications are assumed:
     * - ethernet (eth0, en*)
     * - wireless (wlan0, wlan1, wlp*)
     * - tethered USB (usb*, eth1)
     * - cellular (ppp0, wwan0, wwp*)
     * - fallback
     * @return string
     */ 
    public function getConnectionType(): string
    {
        // get the interface associated with the default route
        $interface = trim(shell_exec("ip route show default | awk '{print $5}'"));

        if (empty($interface)) {
            return 'unknown';
        }
        // classify interface type
        if (preg_match('/^eth\d+|enp\d+s\d+/', $interface)) {
            return 'ethernet';
        }
        if (preg_match('/^wlan\d+|wlp\d+s\d+/', $interface)) {
            return 'wireless';
        }
        if (preg_match('/^usb\d+|eth1$/', $interface)) {
            return 'tethering';
        }
        if (preg_match('/^ppp\d+|wwan\d+|wwp\d+s\d+/', $interface)) {
            return 'cellular';
        }

        // if none match, return the interface name as a fallback
        return "other ($interface)";
    }

    /**
     * Returns a fontawesome icon associated with a connection
     * type/class
     *
     * @param $type
     * @return string
     */
    public function getConnectionIcon($type): string
    {
        switch (strtolower($type)) {
            case 'ethernet':
                return 'fa-ethernet';
            case 'wireless':
                return 'fa-wifi';
            case 'tethering':
                return 'fa-mobile-alt';
            case 'cellular':
                return 'fa-broadcast-tower';
            default:
                return 'fa-question-circle'; // unknown
        }
    }

    /**
     * Retrieves the firewall's current status
     *
     * @return bool status
     */
    public function firewallEnabled(): bool
    {
        $conf = array();
        if (file_exists($this->firewallConfig) ) {
            $conf = parse_ini_file($this->firewallConfig);
        }
        if ($conf["firewall-enable"] == 1) {
            return true;
        }
        return false;
    }

    /**
     * Handles dashboard page actions
     *
     * @param string $state
     * @param array $post
     * @param object $status
     * @param string $interface
     */
    public function handlePageAction(string $state, array $post, $status, string $interface): object
    {
        if (!RASPI_MONITOR_ENABLED) {
            if (isset($post['ifdown_wlan0'])) {
                if ($state === 'up') {
                    $status->addMessage(sprintf(_('Interface %s is going %s'), $interface, _('down')), 'warning');
                    exec('sudo ip link set ' .escapeshellarg($interface). ' down');
                    $status->addMessage(sprintf(_('Interface %s is %s'), $interface, _('down')), 'success');
                } elseif ($details['state'] === 'unknown') {
                    $status->addMessage(_('Interface state unknown'), 'danger');
                } else {
                    $status->addMessage(sprintf(_('Interface %s is already %s'), $interface, _('down')), 'warning');
                }
            } elseif (isset($post['ifup_wlan0'])) {
                if ($state === 'down') {
                    $status->addMessage(sprintf(_('Interface %s is going %s'), $interface, _('up')), 'warning');
                    exec('sudo ip link set ' .escapeshellarg($interface). ' up');
                    exec('sudo ip -s a f label ' .escapeshellarg($interface));
                    usleep(250000);
                    $status->addMessage(sprintf(_('Interface %s is %s'), $interface, _('up')), 'success');
                } elseif ($state === 'unknown') {
                    $status->addMessage(_('Interface state unknown'), 'danger');
                } else {
                    $status->addMessage(sprintf(_('Interface %s is already %s'), $interface, _('up')), 'warning');
                }
            }
            return $status;
        }
    }

}

