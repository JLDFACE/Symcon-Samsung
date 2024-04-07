<?php

require_once("IPSModuleHelper.php");

class SamsungTV extends IPSModuleHelper {

    // Überschreibt die interne IPS_Create($id) Funktion
    public function Create() {
        // Diese Zeile nicht löschen.
        parent::Create();

        $this->RequireParent("{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}");

        $this->CreateVariableProfileInteger("SamsungTVSource", 0x04, 0x69, 1, array(
            array(
                "value" => 0x04,
                "name" => "S-Video"
            ),
            array(
                "value" => 0x08,
                "name" => "Component"
            ),
            array(
                "value" => 0x0c,
                "name" => "AV1 (AV)"
            ),
            array(
                "value" => 0x0d,
                "name" => "AV2"
            ),
            array(
                "value" => 0x0e,
                "name" => "Ext. (SCART1)"
            ),
            array(
                "value" => 0x18,
                "name" => "DVI"
            ),
            array(
                "value" => 0x14,
                "name" => "PC"
            ),
            array(
                "value" => 0x1e,
                "name" => "BNC"
            ),
            array(
                "value" => 0x1f,
                "name" => "DVI_VIDEO"
            ),
            array(
                "value" => 0x20,
                "name" => "Magicinfo"
            ),
            array(
                "value" => 0x21,
                "name" => "HDMI1"
            ),
            array(
                "value" => 0x22,
                "name" => "HDMI1_PC"
            ),
            array(
                "value" => 0x23,
                "name" => "HDMI2"
            ),
            array(
                "value" => 0x24,
                "name" => "HDMI2_PC"
            ),
            array(
                "value" => 0x25,
                "name" => "DispalyPort1"
            ),
            array(
                "value" => 0x26,
                "name" => "DispalyPort2"
            ),
            array(
                "value" => 0x27,
                "name" => "DispalyPort3"
            ),
            array(
                "value" => 0x31,
                "name" => "HDMI3"
            ),
            array(
                "value" => 0x32,
                "name" => "HDMI3_PC"
            ),
            array(
                "value" => 0x33,
                "name" => "HDMI4"
            ),
            array(
                "value" => 0x34,
                "name" => "HDMI4_PC"
            ),
            array(
                "value" => 0x35,
                "name" => "SDI1"
            ),
            array(
                "value" => 0x36,
                "name" => "SDI2"
            ),
            array(
                "value" => 0x37,
                "name" => "SDI3"
            ),
            array(
                "value" => 0x28,
                "name" => "SDI4"
            ),
            array(
                "value" => 0x40,
                "name" => "TV (DTV)"
            ),
            array(
                "value" => 0x50,
                "name" => "Plug In Module"
            ),
            array(
                "value" => 0x55,
                "name" => "HDBaseT"
            ),
            array(
                "value" => 0x56,
                "name" => "OCM"
            ),
            array(
                "value" => 0x60,
                "name" => "Media/MagicInfo S"
            ),
            array(
                "value" => 0x61,
                "name" => "WiDi/Screen Mirroring"
            ),
            array(
                "value" => 0x62,
                "name" => "Internal/USB"
            ),
            array(
                "value" => 0x63,
                "name" => "URL Launcher"
            ),
            array(
                "value" => 0x64,
                "name" => "IWB"
            ),
            array(
                "value" => 0x65,
                "name" => "Web Browser"
            ),
            array(
                "value" => 0x66,
                "name" => "Remote Workspace"
            ),
            array(
                "value" => 0x67,
                "name" => "KIOSK"
            ),
            array(
                "value" => 0x68,
                "name" => "Multi View"
            ),
            array(
                "value" => 0x69,
                "name" => "SmartView+"
            )
        ));

        $this->CreateVariableProfileBoolean("SamsungTVOnlineStatus", array(
            array(
                "value" => true,
                "name" => "Online",
                "color" => 0xff0000
            ),
            array(
                "value" => false,
                "name" => "Offline",
                "color" => 0x00ff00
            )
        ), "", "", "Power");

        $this->RegisterVariableBoolean("OnlineStatus", "Status", "SamsungTVOnlineStatus", 0);
        $this->RegisterVariableBoolean("PowerStatus", "Power", "~Switch", 1);
        $this->RegisterVariableInteger("Source", "Source", "SamsungTVSource", 2);
        $this->RegisterVariableInteger("Volume", "Volume", "~Intensity.100", 3);

        $this->EnableAction("PowerStatus");
        $this->EnableAction("Source");
        $this->EnableAction("Volume");

        $this->RegisterTimer("CheckOnlineStatus", 2000, "STV_RefreshOnlineStatus(" . $this->InstanceID . ");");
        $this->RegisterTimer("QueryDeviceStatus", 2000, 'STV_QueryDeviceStatus(' . $this->InstanceID . ');');

        $this->SetBuffer("pingTimeouts", 0);
    }

    public function Destroy() {
        parent::Destroy();

        $this->SetTimerInterval("CheckOnlineStatus", 0);
        $this->SetTimerInterval("QueryDeviceStatus", 0);
    }

    // Überschreibt die intere IPS_ApplyChanges($id) Funktion
    public function ApplyChanges() {
        // Diese Zeile nicht löschen
        parent::ApplyChanges();
    }

    public function RefreshOnlineStatus() {
        $connectionId = IPS_GetInstance($this->InstanceID)["ConnectionID"];
        $connectionState = IPS_GetProperty($connectionId, "Open");

        $pingTimeouts = $this->GetBuffer("pingTimeouts");
        $host = IPS_GetProperty($connectionId, "Host");

        if (strlen($host) > 0) {
            $status = Sys_Ping($host, 1000);

            if ($status) {
                $pingTimeouts = 0;
            } else {
                $pingTimeouts++;
            }
        } else {
            $pingTimeouts = 4;
        }

        if ($pingTimeouts >= 4 && $connectionState) {
            IPS_SetProperty($connectionId, "Open", false);
            IPS_ApplyChanges($connectionId);
        }

        $this->SetValue("OnlineStatus", !($pingTimeouts >= 4));
        $this->SetBuffer("pingTimeouts", $pingTimeouts);
    }

    public function QueryDeviceStatus() {
        if (!$this->GetValue("OnlineStatus"))
            return;

        $this->SendCommand(0x00);
        $this->SendCommand(0x1b, array(0x81));
    }

    public function SetPower(bool $powerStatus) {
        if (!$this->GetValue("OnlineStatus"))
            return;

        $this->SendCommand(0x11, array($powerStatus ? 0x01 : 0x00));
    }

    public function SetInput(int $input) {
        if (!$this->GetValue("OnlineStatus"))
            return;

        $this->SendCommand(0x11, array($input));
    }

    public function SetVolume(int $volume) {
        if (!$this->GetValue("OnlineStatus"))
            return;

        $this->SendCommand(0x11, array($volume));
    }

    public function PowerOn() {
        $this->SetPower(true);
    }

    public function PowerOff() {
        $this->SetPower(false);
    }

    public function SendCommand(int $cmd, array $data = array()) {
        $connectionId = IPS_GetInstance($this->InstanceID)["ConnectionID"];
        $connectionState = IPS_GetProperty($connectionId, "Open");

        $state = IPS_GetInstance($connectionId)["InstanceStatus"];
        if (($state >= 200 || !$this->GetValue("OnlineStatus")) && $connectionState) {
            IPS_SetProperty($connectionId, "Open", false);
            IPS_ApplyChanges($connectionId);
        }

        if (!$this->GetValue("OnlineStatus"))
            return;

        if ($state == 104 && !$connectionState) {
            IPS_SetProperty($connectionId, "Open", true);
            IPS_ApplyChanges($connectionId);
        } else if ($state != 102) {
            return;
        }

        $chksum = $cmd + 0x00 + count($data);
        $req = "AA" . DecToHex($cmd) . "00" . DecToHex(count($data));

        foreach ($data as $param) {
            $chksum += $param;
            $req .= DecToHex($param);
        }

        while ($chksum > 256)
            $chksum -= 256;

        IPS_LogMessage("DEBUG", $req . DecToHex($chksum));

        $this->SendDataToParent(json_encode([
            'DataID' => '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}',
            'Buffer' => utf8_encode(pack("H*", $req . DecToHex($chksum)))
        ]));
    }

    // Empfangene Daten vom Parent (RX Paket) vom Typ Simpel
    public function ReceiveData($JSONString) {
        $data = json_decode($JSONString, true);
//        $data['Buffer'] = utf8_decode($data['Buffer']);

//        IPS_LogMessage("DATA", print_r($data, true));

        switch ($data["DataID"]) {
            case "{018EF6B5-AB94-40C6-AA53-46943E824ACF}":
                $this->handleSocketInput($data);
                break;
        }
    }

    public function ForwardData($JSONString) {
        $data = json_decode($JSONString, true);
        $data['Buffer'] = utf8_decode($data['Buffer']);

        switch ($data["DataID"]) {
            case "{DD953407-11D6-4FE6-A863-C509D5C5F8C2}":
                $this->SendCommand($data["Buffer"]);
                break;
        }
    }

    private function handleSocketInput($data) {
        //$temp = $this->GetBuffer("incomingData");
        $dataIn = utf8_decode($data['Buffer']);

        $data["hexBuffer"] = bin2hex(utf8_decode($data['Buffer']));
        //$data["tempVariable"] = $temp;

        // Im Meldungsfenster zu Debug zwecken ausgeben
//        $this->LogMessage(print_r($data, true), KL_MESSAGE);

        $msg = substr($data["hexBuffer"], 2, strlen($data["hexBuffer"]) - 4);
        $cmd = substr($msg, 8, 2);
        $monitorId = substr($msg, 2, 2);
        $ack = substr($msg, 6, 2) == "41";

        if (!$ack) {
            $error = substr($msg, 10, 2);
            $this->LogMessage("Command " . $cmd . " failed. Error: " . $error, KL_MESSAGE);
            return;
        }

        $payload = substr($msg, 10);

        switch ($cmd) {
            case "00":
                $this->SetValue("PowerStatus", substr($payload, 0, 2) == "01");
                $this->SetValue("Volume", hexdec(substr($payload, 2, 2)));
                $this->SetValue("Source", hexdec(substr($payload, 6, 2)));

                break;
            case "1b":
                $subcmd = substr($payload, 0, 2);

                switch ($subcmd) {
                    case "81":
                        $mac = "";

                        for ($h = 0; $h < 6; $h++) {
                            $mac .= ":";
                            for ($i = 0; $i < 2; $i++)
                                $mac .= chr(intval(hexdec(substr($payload, $h * 4 + $i * 2 + 2, 2))));
                        }

                        $mac = substr($mac, 1);

                        $this->LogMessage("MAC: " . $mac, KL_MESSAGE);

                        break;
                }
                break;
        }

        $this->LogMessage(print_r($msg, true), KL_MESSAGE);
    }

    public function RequestAction($Ident, $Value): bool {
        if ($Ident == "Power") {
            $this->SendCommand("PO" . (($Value) ? "N" : "F"));
        } else if ($Ident == "Input") {
            $this->SendCommand("IIS:" . $Value);
        } else if ($Ident == "Volume") {
            $this->SendCommand("IIS:" . $Value);
        }

        $this->SetValue($Ident, $Value);

        return true;
    }
}

function DecToHex($dec) {
    return str_pad(strtoupper(dechex($dec)), 2, "0", STR_PAD_LEFT);
}