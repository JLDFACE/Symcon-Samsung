<?php

class SamsungTVConfigurator extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('ScanSubnet', $this->GetDefaultScanSubnet());
        $this->RegisterPropertyInteger('Port', 1515);

        $this->RegisterPropertyString('ManualIP', '');
        $this->RegisterPropertyInteger('ManualPort', 1515);

        $this->RegisterAttributeString('Discovered', '[]');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $auto = $this->DetectLocalSubnet();
        if (!$this->IsValidCIDR($auto)) {
            $auto = '';
        }
        $this->SetBuffer('AutoSubnet', $auto);
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        if (!is_array($form)) {
            $form = [];
        }

        $values = json_decode($this->ReadAttributeString('Discovered'), true);
        if (!is_array($values)) {
            $values = [];
        }

        if (!isset($form['actions']) || !is_array($form['actions'])) {
            $form['actions'] = [];
        }

        foreach ($form['actions'] as &$action) {
            if (isset($action['type']) && $action['type'] === 'Configurator'
                && isset($action['name']) && $action['name'] === 'Devices') {
                $action['values'] = $values;
                break;
            }
        }

        $autoSubnet = $this->GetBuffer('AutoSubnet');
        if ($autoSubnet === '' || $autoSubnet === null) {
            $autoSubnet = $this->DetectLocalSubnet();
        }
        if (!$this->IsValidCIDR($autoSubnet)) {
            $autoSubnet = '';
        }
        if (!isset($form['actions']) || !is_array($form['actions'])) {
            $form['actions'] = [];
        }
        array_unshift($form['actions'], [
            'type' => 'Label',
            'caption' => 'Auto-Subnetz: ' . ($autoSubnet !== '' ? $autoSubnet : 'n/a')
        ]);

        return json_encode($form);
    }

    public function Scan()
    {
        $subnet = $this->GetAutoScanSubnet();
        $port = (int) $this->ReadPropertyInteger('Port');
        if ($port < 1) {
            $port = 1515;
        }

        $ips = $this->ExpandCIDR($subnet, 2048);
        $found = [];

        foreach ($ips as $ip) {
            if ($this->ProbeDevice($ip, $port)) {
                $found[] = $this->BuildRow($ip, $port);
            }
        }

        $this->WriteAttributeString('Discovered', json_encode($found));
    }

    private function GetAutoScanSubnet(): string
    {
        $configured = trim($this->ReadPropertyString('ScanSubnet'));
        $auto = $this->DetectLocalSubnet();
        if (!$this->IsValidCIDR($auto)) {
            $auto = '';
        }

        if ($configured === '' || !$this->IsValidCIDR($configured)) {
            return ($auto !== '') ? $auto : '';
        }

        if ($configured === '192.168.1.0/24' && $auto !== '' && $auto !== $configured) {
            return $auto;
        }

        return $configured;
    }

    private function GetDefaultScanSubnet(): string
    {
        $auto = $this->DetectLocalSubnet();
        if ($this->IsValidCIDR($auto)) {
            return $auto;
        }
        return '192.168.1.0/24';
    }

    private function DetectLocalSubnet(): string
    {
        $entries = [];

        if (function_exists('Sys_GetNetworkInfo')) {
            $info = @Sys_GetNetworkInfo();
            $entries = array_merge($entries, $this->ExtractNetworkEntries($info));
        }
        if (function_exists('Sys_GetNetworkInfoEx')) {
            $info = @Sys_GetNetworkInfoEx();
            $entries = array_merge($entries, $this->ExtractNetworkEntries($info));
        }

        $best = '';
        foreach ($entries as $entry) {
            $cidr = $this->CidrFromEntry($entry);
            if ($cidr === '') {
                continue;
            }
            if ($this->EntryHasGateway($entry)) {
                return $cidr;
            }
            if ($best === '') {
                $best = $cidr;
            }
        }

        if ($best !== '') {
            return $best;
        }

        $fallbackIp = $this->GetFallbackIPv4();
        if ($fallbackIp !== '') {
            return $this->BuildCIDR($fallbackIp, '255.255.255.0', '');
        }

        return '';
    }

    private function ExtractNetworkEntries($info): array
    {
        $entries = [];
        $this->CollectNetworkEntries($info, $entries, 0);
        return $entries;
    }

    private function LooksLikeNetworkEntry($entry): bool
    {
        if (!is_array($entry)) {
            return false;
        }
        $keys = ['IP', 'ip', 'Address', 'Addr', 'IPv4', 'IPv4Address', 'Host'];
        foreach ($keys as $key) {
            if (isset($entry[$key])) {
                return true;
            }
        }
        return false;
    }

    private function CollectNetworkEntries($node, array &$entries, int $depth): void
    {
        if (!is_array($node)) {
            return;
        }
        if ($this->LooksLikeNetworkEntry($node)) {
            $entries[] = $node;
            return;
        }
        if ($depth >= 4) {
            return;
        }
        foreach ($node as $child) {
            $this->CollectNetworkEntries($child, $entries, $depth + 1);
        }
    }

    private function EntryHasGateway($entry): bool
    {
        if (!is_array($entry)) {
            return false;
        }
        $keys = ['Gateway', 'gateway', 'IPv4Gateway'];
        foreach ($keys as $key) {
            if (!isset($entry[$key])) {
                continue;
            }
            $gw = trim((string) $entry[$key]);
            if ($gw !== '' && $gw !== '0.0.0.0') {
                return true;
            }
        }
        return false;
    }

    private function CidrFromEntry($entry): string
    {
        if (!is_array($entry)) {
            return '';
        }

        $ip = $this->FirstValue($entry, ['IP', 'ip', 'Address', 'Addr', 'IPv4', 'IPv4Address', 'Host']);
        $mask = $this->FirstValue($entry, ['Subnet', 'SubnetMask', 'Netmask', 'Mask', 'IPv4Mask']);
        $prefix = $this->FirstValue($entry, ['Prefix', 'PrefixLength', 'CIDR']);

        if ($ip === '') {
            return '';
        }
        if (strpos($ip, '/') !== false) {
            $parts = explode('/', $ip, 2);
            $ip = trim($parts[0]);
            if ($prefix === '') {
                $prefix = trim($parts[1]);
            }
        }

        if ($mask === '' && $prefix === '') {
            $prefix = '24';
        }

        return $this->BuildCIDR($ip, $mask, $prefix);
    }

    private function BuildCIDR($ip, $mask, $prefix): string
    {
        if (!$this->IsUsableIPv4($ip)) {
            return '';
        }

        $prefix = trim((string) $prefix);
        if ($prefix === '' && $mask !== '') {
            if (strpos($mask, '.') !== false) {
                $prefix = (string) $this->NetmaskToCidr($mask);
            } else {
                $prefix = (string) (int) $mask;
            }
        }

        $prefixInt = (int) $prefix;
        if ($prefixInt <= 0 || $prefixInt > 32) {
            return '';
        }

        $ipLong = ip2long($ip);
        if ($ipLong === false) {
            return '';
        }

        $hostBits = 32 - $prefixInt;
        $netLong = $ipLong & (-1 << $hostBits);
        return long2ip($netLong) . '/' . $prefixInt;
    }

    private function NetmaskToCidr($mask): int
    {
        $maskLong = ip2long($mask);
        if ($maskLong === false) {
            return -1;
        }
        if ($maskLong < 0) {
            $maskLong += 4294967296;
        }

        $bin = decbin($maskLong);
        $bin = str_pad($bin, 32, '0', STR_PAD_LEFT);
        if (!preg_match('/^1*0*$/', $bin)) {
            return -1;
        }
        return substr_count($bin, '1');
    }

    private function IsValidCIDR($cidr): bool
    {
        $cidr = trim((string) $cidr);
        if ($cidr === '') {
            return false;
        }
        $parts = explode('/', $cidr);
        if (count($parts) != 2) {
            return false;
        }

        $ip = trim($parts[0]);
        $prefix = (int) trim($parts[1]);
        if (!$this->IsUsableIPv4($ip)) {
            return false;
        }
        return ($prefix >= 0 && $prefix <= 32);
    }

    private function IsUsableIPv4($ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }
        if ($ip === '0.0.0.0') {
            return false;
        }
        if (strpos($ip, '127.') === 0) {
            return false;
        }
        if (strpos($ip, '169.254.') === 0) {
            return false;
        }
        return true;
    }

    private function FirstValue($entry, $keys): string
    {
        foreach ($keys as $key) {
            if (isset($entry[$key]) && $entry[$key] !== '') {
                return (string) $entry[$key];
            }
        }
        return '';
    }

    private function GetFallbackIPv4(): string
    {
        $host = '';
        if (isset($_SERVER['SERVER_ADDR'])) {
            $host = (string) $_SERVER['SERVER_ADDR'];
        } else {
            $host = (string) gethostbyname(gethostname());
        }
        return $this->IsUsableIPv4($host) ? $host : '';
    }

    private function FindInstanceByHostAndPort(string $moduleID, string $host, int $port): int
    {
        $targetHost = $this->NormalizeHost($host);
        if ($targetHost === '') {
            return 0;
        }

        $socketId = $this->FindSocketInstance($targetHost, $port);
        if ($socketId > 0) {
            $deviceId = $this->FindDeviceByConnectionId($moduleID, $socketId);
            if ($deviceId > 0) {
                return $deviceId;
            }
        }

        $ids = IPS_GetInstanceListByModuleID($moduleID);
        foreach ($ids as $id) {
            $data = $this->GetInstanceHostPort($id);
            $instHost = $this->NormalizeHost($data['host']);

            if ($instHost === $targetHost) {
                return (int) $id;
            }
        }
        return 0;
    }

    private function GetInstanceHostPort(int $instanceId): array
    {
        $host = '';
        $port = 0;

        if (IPS_InstanceExists($instanceId)) {
            $host = (string) IPS_GetProperty($instanceId, 'Host');
            $port = (int) IPS_GetProperty($instanceId, 'Port');

            if ($host === '' || $port === 0) {
                $inst = IPS_GetInstance($instanceId);
                $parentId = isset($inst['ConnectionID']) ? (int) $inst['ConnectionID'] : 0;
                if ($parentId > 0 && IPS_InstanceExists($parentId)) {
                    if ($host === '') {
                        $host = (string) IPS_GetProperty($parentId, 'Host');
                    }
                    if ($port === 0) {
                        $port = (int) IPS_GetProperty($parentId, 'Port');
                    }
                }
            }
        }

        return [
            'host' => $host,
            'port' => $port
        ];
    }

    private function FindSocketInstance(string $host, int $port): int
    {
        $targetHost = $this->NormalizeHost($host);
        if ($targetHost === '') {
            return 0;
        }

        $targetPort = $this->NormalizePort($port);
        $socketModuleId = '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}';

        $ids = IPS_GetInstanceListByModuleID($socketModuleId);
        foreach ($ids as $id) {
            $sockHost = $this->NormalizeHost((string) IPS_GetProperty($id, 'Host'));
            $sockPort = $this->NormalizePort((int) IPS_GetProperty($id, 'Port'));
            if ($sockHost === $targetHost && $sockPort === $targetPort) {
                return (int) $id;
            }
        }
        return 0;
    }

    private function FindDeviceByConnectionId(string $moduleID, int $connectionId): int
    {
        $ids = IPS_GetInstanceListByModuleID($moduleID);
        foreach ($ids as $id) {
            $inst = IPS_GetInstance($id);
            $parentId = isset($inst['ConnectionID']) ? (int) $inst['ConnectionID'] : 0;
            if ($parentId === $connectionId) {
                return (int) $id;
            }
        }
        return 0;
    }

    private function NormalizeHost(string $host): string
    {
        $host = trim($host);
        if ($host === '') {
            return '';
        }
        $host = preg_replace('/^[a-z]+:\\/\\//i', '', $host);
        $host = trim($host);

        if (substr_count($host, ':') === 1) {
            $parts = explode(':', $host, 2);
            if (isset($parts[1]) && ctype_digit($parts[1])) {
                $host = $parts[0];
            }
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $host;
        }

        $ip = @gethostbyname($host);
        if ($this->IsUsableIPv4($ip)) {
            return $ip;
        }

        return strtolower($host);
    }

    private function NormalizePort(int $port): int
    {
        if ($port < 1) {
            return 1515;
        }
        return $port;
    }

    public function AddManual()
    {
        $ip = trim($this->ReadPropertyString('ManualIP'));
        $port = (int) $this->ReadPropertyInteger('ManualPort');
        if ($port < 1) {
            $port = 1515;
        }

        if ($ip === '') {
            echo "Fehler: IP-Adresse darf nicht leer sein.";
            return;
        }

        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            echo "Fehler: Ungueltige IP-Adresse.";
            return;
        }

        if (!$this->ProbeDevice($ip, $port)) {
            echo "Fehler: Geraet unter $ip:$port nicht erreichbar oder nicht kompatibel.";
            return;
        }

        $existing = json_decode($this->ReadAttributeString('Discovered'), true);
        if (!is_array($existing)) {
            $existing = [];
        }

        foreach ($existing as $item) {
            if (isset($item['address']) && $item['address'] === $ip) {
                echo "Info: Geraet $ip ist bereits in der Liste.";
                return;
            }
        }

        $existing[] = $this->BuildRow($ip, $port);
        $this->WriteAttributeString('Discovered', json_encode($existing));

        echo "Erfolg: Geraet $ip wurde hinzugefuegt.";
    }

    private function BuildRow(string $ip, int $port): array
    {
        $deviceModuleID = '{CB543CA0-A203-6654-F8B5-3507A157CD68}';
        $existing = $this->FindInstanceByHostAndPort($deviceModuleID, $ip, $port);

        $row = [
            'address' => $ip,
            'info' => 'TCP/' . $port,
            'instanceID' => ($existing > 0) ? $existing : 0
        ];

        if ($existing == 0) {
            $row['create'] = [
                [
                    'moduleID' => $deviceModuleID,
                    'configuration' => [
                        'Host' => $ip,
                        'Port' => $port
                    ]
                ]
            ];
        }

        return $row;
    }

    private function ProbeDevice(string $ip, int $port): bool
    {
        $timeout = 0.2;
        $fp = @fsockopen($ip, $port, $errno, $errstr, $timeout);
        if (!$fp) {
            return false;
        }

        stream_set_timeout($fp, 0, 250000);
        $cmd = $this->BuildCommand(0x00, []);
        @fwrite($fp, $cmd);

        $buf = '';
        $start = microtime(true);
        while (microtime(true) - $start < 0.25) {
            $chunk = fread($fp, 512);
            if ($chunk !== false && $chunk !== '') {
                $buf .= $chunk;
                if (strlen($buf) >= 6) {
                    break;
                }
            } else {
                $meta = stream_get_meta_data($fp);
                if (isset($meta['timed_out']) && $meta['timed_out']) {
                    break;
                }
            }
        }

        fclose($fp);

        return ($buf !== '');
    }

    private function BuildCommand(int $cmd, array $data): string
    {
        $chksum = $cmd + 0x00 + count($data);
        $req = "AA" . $this->DecToHex($cmd) . "00" . $this->DecToHex(count($data));

        foreach ($data as $param) {
            $chksum += $param;
            $req .= $this->DecToHex($param);
        }

        $chksum = $chksum % 256;

        return pack("H*", $req . $this->DecToHex($chksum));
    }

    private function DecToHex(int $dec): string
    {
        return str_pad(strtoupper(dechex($dec)), 2, "0", STR_PAD_LEFT);
    }

    private function ExpandCIDR(string $cidr, int $limit): array
    {
        $cidr = trim($cidr);
        $parts = explode('/', $cidr);
        if (count($parts) != 2) {
            return [];
        }

        $base = trim($parts[0]);
        $mask = (int) $parts[1];
        if ($mask < 0 || $mask > 32) {
            return [];
        }

        $baseLong = ip2long($base);
        if ($baseLong === false) {
            return [];
        }

        $hostBits = 32 - $mask;
        $count = 1 << $hostBits;
        if ($count < 0) {
            $count = 0;
        }

        if ($count > (int) $limit) {
            $count = (int) $limit;
        }

        $netLong = $baseLong & (-1 << $hostBits);

        $ips = [];
        for ($i = 1; $i < $count - 1; $i++) {
            $ips[] = long2ip($netLong + $i);
        }

        if (count($ips) == 0) {
            $ips[] = $base;
        }

        return $ips;
    }
}
