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
        if ($auto !== '') {
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
        if (!is_array($info)) {
            return $entries;
        }

        if ($this->LooksLikeNetworkEntry($info)) {
            $entries[] = $info;
            return $entries;
        }

        foreach ($info as $entry) {
            if ($this->LooksLikeNetworkEntry($entry)) {
                $entries[] = $entry;
            }
        }
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
        if ($prefixInt < 0 || $prefixInt > 32) {
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

        return [
            'address' => $ip,
            'info' => 'TCP/' . $port,
            'instanceID' => 0,
            'create' => [
                [
                    'moduleID' => $deviceModuleID,
                    'configuration' => [
                        'Host' => $ip,
                        'Port' => $port
                    ]
                ]
            ]
        ];
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
