<?php
declare(strict_types=1);

class RoomMotionLights extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // --- Properties (werden später in der Form gepflegt) ---
        $this->RegisterPropertyString('MotionVars', '[]');      // Bewegungsmelder (VariableIDs)
        $this->RegisterPropertyString('Lights', '[]');          // Aktoren-Liste
        $this->RegisterPropertyString('InhibitVars', '[]');     // Raum-Stati
        $this->RegisterPropertyString('GlobalInhibits', '[]');  // Globale Stati
        $this->RegisterPropertyInteger('TimeoutSec', 60);       // Fester Timeout (s)
        $this->RegisterPropertyInteger('TimeoutVar', 0);        // VariableID für dynamischen Timeout (optional)
        $this->RegisterPropertyInteger('DefaultDim', 60);       // %
        $this->RegisterPropertyBoolean('ManualAutoOff', true);  // Auto-Off auch bei manuell
        $this->RegisterPropertyInteger('LuxVar', 0);            // optional
        $this->RegisterPropertyInteger('LuxMax', 50);           // optional

        // --- Basis-Objekte ---
        $this->RegisterVariableBoolean('Override', 'Automatik (Auto-ON) deaktivieren', '~Switch', 1);
        $this->RegisterTimer('AutoOff', 0, 'RML_AutoOff($_IPS[\'TARGET\']);'); // Timer-Callback
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        // Später: Events/Timer und interne Struktur bauen
    }

    // Timer-Callback (Platzhalter)
    public function AutoOff()
    {
        IPS_LogMessage('RoomMotionLights', 'AutoOff() placeholder for instance '.$this->InstanceID);
    }

    public function GetConfigurationForm(): string
    {
        return json_encode([
            'elements' => [
                ['type' => 'Label', 'caption' => 'RoomMotionLights – Modul-Gerüst installiert.'],
                ['type' => 'ExpansionPanel', 'caption' => 'Verhalten', 'items' => [
                    ['type' => 'NumberSpinner', 'name' => 'TimeoutSec', 'caption' => 'Timeout Standard (s)', 'minimum' => 5, 'maximum' => 3600],
                    ['type' => 'SelectVariable', 'name' => 'TimeoutVar', 'caption' => 'Timeout aus Variable (optional)'],
                    ['type' => 'NumberSpinner', 'name' => 'DefaultDim', 'caption' => 'Default Dim (%)', 'minimum' => 1, 'maximum' => 100],
                    ['type' => 'CheckBox', 'name' => 'ManualAutoOff', 'caption' => 'Manuelles Auto-Off aktiv']
                ]]
            ],
            'actions' => [
                ['type' => 'Button', 'caption' => 'Test: Auto-Off auslösen', 'onClick' => 'RML_AutoOff($id);']
            ],
            'status' => []
        ]);
    }
}
