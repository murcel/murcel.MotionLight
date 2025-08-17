<?php
class RoomMotionLights extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // Bewegungsmelder
        $this->RegisterPropertyInteger('MotionVariable', 0);

        // Lichter (Liste)
        $this->RegisterPropertyString('Lights', '[]');

        // Globale und Raum-Status Variablen
        $this->RegisterPropertyString('GlobalInhibit', '[]');
        $this->RegisterPropertyString('RoomInhibit', '[]');

        // Grundkonfig
        $this->RegisterPropertyInteger('TimeoutSec', 300);
        $this->RegisterPropertyInteger('DefaultDim', 60);
        $this->RegisterPropertyInteger('LuxMax', 0);

        // Flags
        $this->RegisterPropertyBoolean('ManualAutoOff', false);

        // Statusvariablen
        $this->RegisterVariableBoolean('Override', 'Automatik deaktivieren', '~Switch', 1);
        $this->EnableAction('Override');

        $this->RegisterVariableInteger('Set_TimeoutSec', 'Timeout (s)', '', 2);
        $this->EnableAction('Set_TimeoutSec');
        $this->SetValue('Set_TimeoutSec', 300);

        $this->RegisterVariableInteger('Set_DefaultDim', 'Standard-Dimmwert (%)', '', 3);
        $this->EnableAction('Set_DefaultDim');
        $this->SetValue('Set_DefaultDim', 60);

        $this->RegisterVariableInteger('Set_LuxMax', 'Maximaler Lux-Wert', '', 4);
        $this->EnableAction('Set_LuxMax');
        $this->SetValue('Set_LuxMax', 0);

        $this->RegisterVariableBoolean('Set_ManualAutoOff', 'Manuelles Auto-Off aktiv', '~Switch', 5);
        $this->EnableAction('Set_ManualAutoOff');
        $this->SetValue('Set_ManualAutoOff', false);

        // Szene-Attribute
        $this->RegisterAttributeString('SceneRestore', json_encode([]));

        // Timer
        $this->RegisterTimer('AutoOff', 0, 'RML_AutoOff($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $motion = $this->ReadPropertyInteger('MotionVariable');
        if ($motion > 0 && @IPS_VariableExists($motion)) {
            $this->RegisterMessage($motion, VM_UPDATE);
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === VM_UPDATE && $SenderID == $this->ReadPropertyInteger('MotionVariable')) {
            $val = GetValueBoolean($SenderID);
            if ($val) {
                $this->handleMotionDetected();
            }
        }
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Override':
                SetValueBoolean($this->GetIDForIdent('Override'), (bool)$Value);
                break;

            case 'Set_TimeoutSec':
                $val = max(5, min(3600, (int)$Value));
                SetValueInteger($this->GetIDForIdent('Set_TimeoutSec'), $val);
                if ($this->GetTimerInterval('AutoOff') > 0) {
                    $this->armAutoOffTimer();
                }
                break;

            case 'Set_DefaultDim':
                $val = max(1, min(100, (int)$Value));
                SetValueInteger($this->GetIDForIdent('Set_DefaultDim'), $val);
                break;

            case 'Set_LuxMax':
                SetValueInteger($this->GetIDForIdent('Set_LuxMax'), max(0, (int)$Value));
                break;

            case 'Set_ManualAutoOff':
                SetValueBoolean($this->GetIDForIdent('Set_ManualAutoOff'), (bool)$Value);
                break;
        }
    }

    private function handleMotionDetected()
    {
        // Stati prüfen
        if ($this->GetValue('Override')) {
            $this->SendDebug('Motion', 'Überschrieben, keine Aktion', 0);
            return;
        }
        foreach (json_decode($this->ReadPropertyString('GlobalInhibit')) as $var) {
            if ($var > 0 && GetValueBoolean($var)) return;
        }
        foreach (json_decode($this->ReadPropertyString('RoomInhibit')) as $var) {
            if ($var > 0 && GetValueBoolean($var)) return;
        }

        // Szene wiederherstellen oder Default setzen
        $scene = json_decode($this->ReadAttributeString('SceneRestore'), true);
        if (!empty($scene)) {
            $this->restoreScene($scene);
        } else {
            $this->switchLightsOn();
        }

        $this->armAutoOffTimer();
    }

    private function switchLightsOn()
    {
        $lights = json_decode($this->ReadPropertyString('Lights'), true);
        $dim = $this->GetValue('Set_DefaultDim');

        foreach ($lights as $light) {
            if ($light['VarType'] === 'Dimmer') {
                $varID = $light['VarID'];
                if (@IPS_VariableExists($varID)) {
                    $profile = IPS_GetVariable($varID)['VariableProfile'];
                    $max = $profile ? IPS_GetVariableProfile($profile)['MaxValue'] : 100;
                    $val = (int)round($dim / 100 * $max);
                    RequestAction($varID, $val);
                }
            } elseif ($light['VarType'] === 'Switch') {
                $varID = $light['VarID'];
                if (@IPS_VariableExists($varID)) {
                    RequestAction($varID, true);
                }
            }
        }
    }

    private function armAutoOffTimer()
    {
        $sec = $this->GetValue('Set_TimeoutSec');
        $this->SetTimerInterval('AutoOff', $sec * 1000);
    }

    public function AutoOff()
    {
        $this->storeCurrentScene();
        $lights = json_decode($this->ReadPropertyString('Lights'), true);

        foreach ($lights as $light) {
            $varID = $light['VarID'];
            if (@IPS_VariableExists($varID)) {
                if ($light['VarType'] === 'Dimmer') {
                    RequestAction($varID, 0);
                } elseif ($light['VarType'] === 'Switch') {
                    RequestAction($varID, false);
                }
            }
        }

        $this->SetTimerInterval('AutoOff', 0);
    }

    // Szene sichern
    private function storeCurrentScene()
    {
        $lights = json_decode($this->ReadPropertyString('Lights'), true);
        $scene = [];

        foreach ($lights as $light) {
            $varID = $light['VarID'];
            if (@IPS_VariableExists($varID)) {
                $scene[$varID] = GetValue($varID);
            }
        }

        $this->WriteAttributeString('SceneRestore', json_encode($scene));
    }

    private function restoreScene(array $scene)
    {
        foreach ($scene as $varID => $val) {
            if (@IPS_VariableExists($varID)) {
                RequestAction($varID, $val);
            }
        }
        $this->WriteAttributeString('SceneRestore', json_encode([]));
    }

    // Debug-Buttons
    public function DebugStoreScene(): void
    {
        $this->WriteAttributeString('SceneRestore', json_encode($this->captureCurrentScene()));
        $this->SendDebug('DebugStoreScene', 'Scene stored', 0);
    }

    public function DebugRestoreScene(): void
    {
        $scene = json_decode($this->ReadAttributeString('SceneRestore'), true);
        $this->restoreScene($scene);
        $this->SendDebug('DebugRestoreScene', 'Scene restored', 0);
    }

    public function DebugClearScene(): void
    {
        $this->WriteAttributeString('SceneRestore', json_encode([]));
        $this->SendDebug('DebugClearScene', 'Scene cleared', 0);
    }

    private function captureCurrentScene(): array
    {
        $lights = json_decode($this->ReadPropertyString('Lights'), true);
        $scene = [];
        foreach ($lights as $light) {
            $varID = $light['VarID'];
            if (@IPS_VariableExists($varID)) {
                $scene[$varID] = GetValue($varID);
            }
        }
        return $scene;
    }

    // Öffentliche Wrapper-Methoden (werden zu RML_* generiert)
    public function SetTimeoutSec(int $seconds): void
    {
        $this->RequestAction('Set_TimeoutSec', $seconds);
    }
    public function SetDefaultDim(int $percent): void
    {
        $this->RequestAction('Set_DefaultDim', $percent);
    }
    public function SetLuxMax(int $lux): void
    {
        $this->RequestAction('Set_LuxMax', $lux);
    }
    public function SetManualAutoOff(bool $on): void
    {
        $this->RequestAction('Set_ManualAutoOff', $on);
    }
}