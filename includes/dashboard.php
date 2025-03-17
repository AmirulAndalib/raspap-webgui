<?php

require_once 'includes/config.php';
require_once 'includes/wifi_functions.php';
require_once 'includes/functions.php';

/**
 * Displays the dashboard
 */
function DisplayDashboard(): void
{
    // instantiate RaspAP objects
    $status = new \RaspAP\Messages\StatusMessage;
    $system = new \RaspAP\System\Sysinfo;
    $pluginManager = \RaspAP\Plugins\PluginManager::getInstance();

    // set AP and client interface session vars
    getWifiInterface();

    $interface = $_SESSION['ap_interface'] ?? 'wlan0';
    $clientInterface = $_SESSION['wifi_client_interface'];
    $hostname = $system->hostname();
    $revision = $system->rpiRevision();
    $hostapd = $system->hostapdStatus();
    $adblock = $system->adBlockStatus();
    $vpn = $system->getActiveVpnInterface();
    $frequency = getFrequencyBand($interface);
    $details = getInterfaceDetails($interface);
    $wireless = getWirelessDetails($interface);
    $connectedBSSID = getConnectedBSSID($interface);
    $state = strtolower($details['state']);
    $plugins = $pluginManager->getInstalledPlugins();

    $arrHostapdConf = parse_ini_file(RASPI_CONFIG.'/hostapd.ini');
    $bridgedEnable = $arrHostapdConf['BridgedEnable'];

    // handle page actions
    if (!empty($_POST)) {
        $status = handlePageAction($state, $_POST, $status, $interface);
        // refresh interface details + state
        $details = getInterfaceDetails($interface);
        $state = strtolower($details['state']);
    }

    $ipv4Address = $details['ipv4'];
    $ipv4Netmask = $details['ipv4_netmask'];
    $macAddress = $details['mac'];
    $ssid = $wireless['ssid'];
    $bridgedStatus = ($bridgedEnable == 1) ? "active" : "";
    $hostapdStatus = ($hostapd[0] == 1) ?  "active" : "";
    $adblockStatus = ($adblock == true) ?  "active" : "";
    $varName = "freq" . str_replace('.', '', $frequency) . "active";
    $$varName = "active";
    $vpnStatus = $vpn ? "active" : "inactive";
    if ($vpn) {
        $vpnManaged = getVpnManged($vpn);
    }
    $firewallInstalled = array_filter($plugins, fn($p) => str_ends_with($p, 'Firewall')) ? true : false;
    if (!$firewallInstalled) {
        $firewallUnavailable = '<i class="fas fa-slash fa-stack-1x"></i>';
    }
    echo renderTemplate(
        "dashboard", compact(
            "clients",
            "interface",
            "clientInterface",
            "state",
            "bridgedStatus",
            "hostapdStatus",
            "adblockStatus",
            "vpnStatus",
            "vpnManaged",
            "firewallUnavailable",
            "status",
            "ipv4Address",
            "ipv4Netmask",
            "ipv6Address",
            "macAddress",
            "ssid",
            "bssid",
            "frequency",
            "freq5active",
            "freq24active",
            "revision"
        )
    );
}

/*
 * Returns the management page for an associated VPN
 *
 * @param string $interface
 * @return string
 * @todo  Determine if VPN provider is active
 */
function getVpnManged(?string $interface = null): ?string
{
    return match ($interface) {
        'wg0' => 'wg_conf',
        'tun0' => 'openvpn_conf',
        'tailscale0' => 'plugin__Tailscale',
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
function getFrequencyBand(string $interface): ?string
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
function getInterfaceDetails(string $interface): array {
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
        'mac' => getMacAddress($cleanOutput),
        'ipv4' => getIPv4Addresses($cleanOutput),
        'ipv4_netmask' => getIPv4Netmasks($cleanOutput),
        'ipv6' => getIPv6Addresses($cleanOutput),
        'state' => getInterfaceState($cleanOutput),
    ];
}

function getMacAddress(string $output): string {
    return preg_match('/link\/ether ([0-9a-f:]+)/i', $output, $matches) ? $matches[1] : _('No MAC Address Found');
}

function getIPv4Addresses(string $output): string {
    if (!preg_match_all('/inet (\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})\/([0-3][0-9])/i', $output, $matches, PREG_SET_ORDER)) {
        return 'None';
    }

    $addresses = array_column($matches, 1);
    return implode(' ', $addresses);
}

function getIPv4Netmasks(string $output): string {
    if (!preg_match_all('/inet (\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})\/([0-3][0-9])/i', $output, $matches, PREG_SET_ORDER)) {
        return '-';
    }

    $netmasks = array_map(fn($match) => long2ip(-1 << (32 - (int)$match[2])), $matches);
    return implode(' ', $netmasks);
}

function getIPv6Addresses(string $output): string {
    return preg_match_all('/inet6 ([a-f0-9:]+)/i', $output, $matches) && isset($matches[1])
        ? implode(' ', $matches[1])
        : _('No IPv6 Address Found');
}

function getInterfaceState(string $output): string {
    return preg_match('/state (UP|DOWN)/i', $output, $matches) ? $matches[1] : 'unknown';
}

function getWirelessDetails(string $interface): array {
    $output = shell_exec('iw dev ' . escapeshellarg($interface) . ' info');
    if (!$output) {
        return ['bssid' => '-', 'ssid' => '-'];
    }
    $cleanOutput = preg_replace('/\s\s+/', ' ', trim($output)); // Fix here

    return [
        'bssid' => getConnectedBSSID($cleanOutput),
        'ssid' => getSSID($cleanOutput),
    ];
}

function getConnectedBSSID(string $output): string {
    return preg_match('/Connected to (([0-9A-Fa-f]{2}:){5}([0-9A-Fa-f]{2}))/i', $output, $matches)
        ? $matches[1]
        : '-';
}

function getSSID(string $output): string {
    return preg_match('/ssid ([^\n\s]+)/i', $output, $matches)
        ? $matches[1]
        : '-';
}

/**
 * Handles dashboard page actions
 *
 * @param string $state
 * @param array $post
 * @param object $status
 * @param string $interface
 */
function handlePageAction(string $state, array $post, $status, string $interface): object
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

