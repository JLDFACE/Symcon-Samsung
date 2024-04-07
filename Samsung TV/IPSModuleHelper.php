<?php

class IPSModuleHelper extends IPSModule {

    protected function CreateVariableProfileBoolean(string $name, array $values = null, string $prefix = "", string $suffix = "", string $icon = "") {
        $this->CreateVariableProfile($name, 0, $values, $prefix, $suffix, $icon);
    }

    protected function CreateVariableProfileInteger(string $name, int $min = null, int $max = null, int $step = null, array $values = null, string $prefix = "", string $suffix = "", string $icon = "") {
        $this->CreateVariableProfile($name, 1, $values, $prefix, $suffix, $icon);

        if ($min != null && $max != null && $step != null)
            IPS_SetVariableProfileValues($name, $min, $max, $step);
    }

    protected function CreateVariableProfileFloat(string $name, float $min, float $max, float $step, array $values = null, string $prefix = "", string $suffix = "", string $icon = "") {
        $this->CreateVariableProfile($name, 2, $values, $prefix, $suffix, $icon);

        if ($min != null && $max != null && $step != null)
            IPS_SetVariableProfileValues($name, $min, $max, $step);
    }

    protected function CreateVariableProfileString(string $name, array $values = null, string $prefix = "", string $suffix = "", string $icon = "") {
        $this->CreateVariableProfile($name, 3, $values, $prefix, $suffix, $icon);
    }

    protected function CreateVariableProfile(string $name, int $type, array $values, string $prefix, string $suffix, string $icon) {
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, $type);
        }

        if (strlen($icon) > 0)
            IPS_SetVariableProfileIcon($name, $icon);

        if (strlen($prefix) > 0 || strlen($suffix) > 0)
            IPS_SetVariableProfileText($name, $prefix, $suffix);

        if ($values != null)
            foreach ($values as $value)
                IPS_SetVariableProfileAssociation($name, $value["value"], $value["name"] ?? "", $value["icon"] ?? "", $value["color"] ?? -1);
    }
}
