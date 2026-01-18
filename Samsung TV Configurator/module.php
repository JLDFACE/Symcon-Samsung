<?php

class SamsungTVConfigurator extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('ScanSubnet', '192.168.1.0/24');
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
        $subnet = trim($this->ReadPropertyString('ScanSubnet'));
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
