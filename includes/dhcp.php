<?php

require_once 'includes/status_messages.php';
require_once 'config.php';

/**
 * Manage DHCP configuration
 */
function DisplayDHCPConfig()
{

    $status = new StatusMessages();
    if (!RASPI_MONITOR_ENABLED) {
        if (isset($_POST['savedhcpdsettings'])) {
            $errors = '';
            define('IFNAMSIZ', 16);
            if (!preg_match('/^[a-zA-Z0-9]+$/', $_POST['interface']) 
                || strlen($_POST['interface']) >= IFNAMSIZ
            ) {
                $errors .= _('Invalid interface name.').'<br />'.PHP_EOL;
            }

            if (!preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\z/', $_POST['RangeStart']) 
                && !empty($_POST['RangeStart'])
            ) {  // allow ''/null ?
                $errors .= _('Invalid DHCP range start.').'<br />'.PHP_EOL;
            }

            if (!preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\z/', $_POST['RangeEnd']) 
                && !empty($_POST['RangeEnd'])
            ) {  // allow ''/null ?
                $errors .= _('Invalid DHCP range end.').'<br />'.PHP_EOL;
            }

            if (!ctype_digit($_POST['RangeLeaseTime']) && $_POST['RangeLeaseTimeUnits'] !== 'infinite') {
                $errors .= _('Invalid DHCP lease time, not a number.').'<br />'.PHP_EOL;
            }

            if (!in_array($_POST['RangeLeaseTimeUnits'], array('m', 'h', 'd', 'infinite'))) {
                $errors .= _('Unknown DHCP lease time unit.').'<br />'.PHP_EOL;
            }

            $return = 1;
            if (empty($errors)) {
                $config = 'interface='.$_POST['interface'].PHP_EOL.
                    'dhcp-range='.$_POST['RangeStart'].','.$_POST['RangeEnd'].
                    ',255.255.255.0,';
                if ($_POST['RangeLeaseTimeUnits'] !== 'infinite') {
                    $config .= $_POST['RangeLeaseTime'];
                }

                $config .= $_POST['RangeLeaseTimeUnits'].PHP_EOL;

                for ($i=0; $i < count($_POST["static_leases"]["mac"]); $i++) {
                    $mac = trim($_POST["static_leases"]["mac"][$i]);
                    $ip  = trim($_POST["static_leases"]["ip"][$i]);
                    if ($mac != "" && $ip != "") {
                        $config .= "dhcp-host=$mac,$ip".PHP_EOL;
                    }
                }

                if ($_POST['no-resolv'] == "1") {
                    $config .= "no-resolv".PHP_EOL;
                }
                foreach ($_POST['server'] as $server) {
                    $config .= "server=$server".PHP_EOL;
                }
                if ($_POST['log-dhcp'] == "1") {
                  $config .= "log-dhcp".PHP_EOL;
                }
                if ($_POST['log-queries'] == "1") {
                  $config .= "log-queries".PHP_EOL;
                }
                if ($_POST['DNS1']) {
                    $config .= "dhcp-option=6," . $_POST['DNS1'];
                    if ($_POST['DNS2']) {
                        $config .= ','.$_POST['DNS2'];
                    }
                    $config .= PHP_EOL;
                }

                $config .= "log-facility=/tmp/dnsmasq.log".PHP_EOL;
                $config .= "conf-dir=/etc/dnsmasq.d".PHP_EOL;
                file_put_contents("/tmp/dnsmasqdata", $config);

                // handle DHCP for eth0 option
                if ($_POST['dhcp-eth0'] == "1" && $_POST['interface'] == "eth0") {
                    $dhcp_cfg = file_get_contents(RASPI_DHCPCD_CONFIG);
                    if (!preg_match('/inteface eth0/', $dhcp_cnf)) {
                        // set dhcp values from ini, fallback to default if undefined
                        $eth0_cfg = parse_ini_file(RASPI_CONFIG_NETWORKING.'/eth0.ini', false, INI_SCANNER_RAW);
                        $ip_address = ($eth0_cfg['ip_address'] == '') ? '172.16.10.1/24' : $eth0_cfg['ip_address'];
                        $domain_name_server = ($eth0_cfg['domain_name_server'] =='') ? '1.1.1.1 8.8.8.8' : $eth0_cfg['domain_name_server'];

                        // append eth0 config to dhcpcd.conf
                        $cfg = $dhcp_conf;
                        $cfg[] = '# RaspAP '.$_POST['interface'].' configuration';
                        $cfg[] = 'interface '.$_POST['interface'];
                        $cfg[] = 'static ip_address='.$ip_address;
                        $cfg[] = 'static domain_name_server='.$domain_name_server;
                        $cfg[] = PHP_EOL;
                        $cfg = join(PHP_EOL, $cfg);
                        $dhcp_cfg .= $cfg;
                        file_put_contents("/tmp/dhcpddata", $dhcp_cfg);
                        system('sudo cp /tmp/dhcpddata '.RASPI_DHCPCD_CONFIG, $return);
                        $status->addMessage('DHCP configuration for eth0 added.', 'success');
                    }
                    system('sudo cp /tmp/dnsmasqdata '.RASPI_DNSMASQ_ETH0, $return);
                    $status->addMessage('Dnsmasq configuration for eth0 added.', 'success');
                } elseif (!isset($_POST['dhcp-eth0']) && file_exists(RASPI_DNSMASQ_ETH0)) {
                    // todo: remove dhcpcd eth0 conf
                    system('sudo rm '.RASPI_DNSMASQ_ETH0, $return);
                    $status->addMessage('Dnsmasq configuration for eth0 removed.', 'success');
                } else {
                    system('sudo cp /tmp/dnsmasqdata '.RASPI_DNSMASQ_CONFIG, $return);
                }

            } else {
                $status->addMessage($errors, 'danger');
            }

            if ($return == 0) {
                $status->addMessage('Dnsmasq configuration updated successfully.', 'success');
            } else {
                $status->addMessage('Dnsmasq configuration failed to be updated.', 'danger');
            }
        }
    }

    exec('pidof dnsmasq | wc -l', $dnsmasq);
    $dnsmasq_state = ($dnsmasq[0] > 0);

    if (!RASPI_MONITOR_ENABLED) {
        if (isset($_POST['startdhcpd'])) {
            if ($dnsmasq_state) {
                $status->addMessage('dnsmasq already running', 'info');
            } else {
                exec('sudo /bin/systemctl start dnsmasq.service', $dnsmasq, $return);
                if ($return == 0) {
                    $status->addMessage('Successfully started dnsmasq', 'success');
                    $dnsmasq_state = true;
                } else {
                    $status->addMessage('Failed to start dnsmasq', 'danger');
                }
            }
        } elseif (isset($_POST['stopdhcpd'])) {
            if ($dnsmasq_state) {
                exec('sudo /bin/systemctl stop dnsmasq.service', $dnsmasq, $return);
                if ($return == 0) {
                    $status->addMessage('Successfully stopped dnsmasq', 'success');
                    $dnsmasq_state = false;
                } else {
                    $status->addMessage('Failed to stop dnsmasq', 'danger');
                }
            } else {
                $status->addMessage('dnsmasq already stopped', 'info');
            }
        }
    }

    $serviceStatus = $dnsmasq_state ? "up" : "down";

    exec('cat '. RASPI_DNSMASQ_CONFIG, $return);
    $conf = ParseConfig($return);
    $arrRange = explode(",", $conf['dhcp-range']);
    $RangeStart = $arrRange[0];
    $RangeEnd = $arrRange[1];
    $RangeMask = $arrRange[2];
    $leaseTime = $arrRange[3];
    $dhcpHost = $conf["dhcp-host"];
    $dhcpHost = empty($dhcpHost) ? [] : $dhcpHost;
    $dhcpHost = is_array($dhcpHost) ? $dhcpHost : [ $dhcpHost ];
    $upstreamServers = is_array($conf['server']) ? $conf['server'] : [ $conf['server'] ];
    $upstreamServers = array_filter($upstreamServers);

    $DNS1 = '';
    $DNS2 = '';
    if (isset($conf['dhcp-option'])) {
        $arrDns = explode(",", $conf['dhcp-option']);
        if ($arrDns[0] == '6') {
            if (count($arrDns) > 1) {
                $DNS1 = $arrDns[1];
            }
            if (count($arrDns) > 2) {
                $DNS2 = $arrDns[2];
            }
        }
    }
  
    $hselected = '';
    $mselected = '';
    $dselected = '';
    $infiniteselected = '';
    preg_match('/([0-9]*)([a-z])/i', $leaseTime, $arrRangeLeaseTime);
    if ($leaseTime === 'infinite') {
        $infiniteselected = ' selected="selected"';
    } else {
        switch ($arrRangeLeaseTime[2]) {
        case 'h':
            $hselected = ' selected="selected"';
            break;
        case 'm':
            $mselected = ' selected="selected"';
            break;
        case 'd':
            $dselected = ' selected="selected"';
            break;
        }
    }
    if (file_exists(RASPI_DNSMASQ_ETH0)) {
        $dhcp_eth0 = 1;
    }
    exec("ip -o link show | awk -F': ' '{print $2}'", $interfaces);
    exec('cat ' . RASPI_DNSMASQ_LEASES, $leases);

    echo renderTemplate(
        "dhcp", compact(
            "status",
            "serviceStatus",
            "RangeStart",
            "RangeEnd",
            "DNS1",
            "DNS2",
            "upstreamServers",
            "arrRangeLeaseTime",
            "mselected",
            "hselected",
            "dselected",
            "infiniteselected",
            "dnsmasq_state",
            "conf",
            "dhcpHost",
            "interfaces",
            "leases",
            "dhcp_eth0"
        )
    );
}
