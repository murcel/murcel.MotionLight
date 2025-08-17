<?php
declare(strict_types=1);

class RoomMotionLights extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // --- Properties (Konfiguration) ---
        $this->RegisterPropertyInteger('MotionVariable', 0);
        $this->RegisterPropertyString('Lights', '[]');          // [{VarType:'Dimmer|Switch', VarID:int}]
        $this->RegisterPropertyString('GlobalInhibit', '[]');   // [int] oder [{var:int}]
        $this->RegisterPropertyString('RoomInhibit', '[]');     // [int] oder [{var:int}]
        $this->RegisterPropertyInteger('LuxVar', 0);            // optional: Lux-Quelle
        $this->RegisterPropertyInteger('TimeoutSec', 300);
        $this->RegisterPropertyInteger('DefaultDim', 60);
        $this->RegisterPropertyInteger('LuxMax', 0);
        $this->RegisterPropertyBoolean('ManualAutoOff', false);

        // --- Instanz-Variablen (runtime stellbar / View) ---
        $this->RegisterVariableBoolean('Override', 'Automatik deaktivieren', '~Switch', 1);
        $this->EnableAction('Override');

        $this->RegisterVariableInteger('Set_TimeoutSec', 'Timeout (s)', '', 2);
        $this->EnableAction('Set_TimeoutSec');
        if ($this->GetValue('Set_TimeoutSec') === 0) {
            $this->SetValue('Set_TimeoutSec', 300);
        }

        $this->RegisterVariableInteger('Set_DefaultDim', 'Standard-Dimmwert (%)', '~Intensity.100', 3);
        $this->EnableAction('Set_DefaultDim');
        if ($this->GetValue('Set_DefaultDim') === 0) {
            $this->SetValue('Set_DefaultDim', 60);
        }

        $this->RegisterVariableInteger('Set_LuxMax', 'Maximaler Lux-Wert', '', 4);
        $this->EnableAction('Set_LuxMax');
        if ($this->GetValue('Set_LuxMax') === 0) {
            $this->SetValue('Set_LuxMax', (int)$this->ReadPropertyInteger('LuxMax'));
        }

        $this->RegisterVariableBoolean('Set_ManualAutoOff', 'Manuelles Auto-Off aktiv', '~Switch', 5);
        $this->EnableAction('Set_ManualAutoOff');
        $this->SetValue('Set_ManualAutoOff', (bool)$this->ReadPropertyBoolean('ManualAutoOff'));

        // Szene-Backup
        $this->RegisterAttributeString('SceneRestore', json_encode([]));

        // Timer
        $this->RegisterTimer('AutoOff', 0, 'RML_AutoOff($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // alte Registrierungen zurücksetzen
        @$this->UnregisterMessage($this->ReadPropertyInteger('MotionVariable'), VM_UPDATE);

        // Motion registrieren
        $mv = $this->ReadPropertyInteger('MotionVariable');
        if ($mv > 0 && @IPS_VariableExists($mv)) {
            $this->RegisterMessage($mv, VM_UPDATE);
        }

        // Inhibits registrieren (optional)
        foreach ($this->getIDList('GlobalInhibit') as $vid) {
            $this->RegisterMessage($vid, VM_UPDATE);
        }
        foreach ($this->getIDList('RoomInhibit') as $vid) {
            $this->RegisterMessage($vid, VM_UPDATE);
        }

        // Lichter registrieren (für "manuelles Auto-Off")
        foreach ($this->getLights() as $light) {
            $id = (int)($light['VarID'] ?? 0);
            if ($id > 0 && @IPS_VariableExists($id)) {
                $this->RegisterMessage($id, VM_UPDATE);
            }
        }
    }

    public function GetConfigurationForm(): string
    {
        return json_encode([
            'elements' => [
                // Bewegungsmelder
                ['type' => 'ExpansionPanel', 'caption' => 'Bewegungsmelder', 'items' => [
                    ['type' => 'SelectVariable', 'name' => 'MotionVariable', 'caption' => 'Bewegungs-Variable (Bool)']
                ]],
                // Lichter
                ['type' => 'ExpansionPanel', 'caption' => 'Lichter', 'items' => [
                    [
                        'type' => 'List', 'name' => 'Lights', 'caption' => 'Akteure',
                        'columns' => [
                            [
                                'caption' => 'Typ', 'name' => 'VarType', 'width' => '140px', 'add' => 'Dimmer',
                                'edit' => [
                                    'type' => 'Select',
                                    'options' => [
                                        ['caption' => 'Dimmer',  'value' => 'Dimmer'],
                                        ['caption' => 'Schalter','value' => 'Switch']
                                    ]
                                ]
                            ],
                            [
                                'caption' => 'Variable', 'name' => 'VarID', 'width' => '320px', 'add' => 0,
                                'edit' => ['type' => 'SelectVariable']
                            ]
                        ],
                        'add' => true, 'delete' => true
                    ]
                ]],
                // Stati / Inhibits
                ['type' => 'ExpansionPanel', 'caption' => 'Stati (Inhibits)', 'items' => [
                    [
                        'type' => 'List', 'name' => 'RoomInhibit', 'caption' => 'Raum-Stati (Bool TRUE blockiert)',
                        'columns' => [[
                            'caption' => 'Variable', 'name' => 'var', 'width' => '320px',
                            'add' => 0, 'edit' => ['type' => 'SelectVariable']
                        ]],
                        'add' => true, 'delete' => true
                    ],
                    [
                        'type' => 'List', 'name' => 'GlobalInhibit', 'caption' => 'Globale Stati (Bool TRUE blockiert)',
                        'columns' => [[
                            'caption' => 'Variable', 'name' => 'var', 'width' => '320px',
                            'add' => 0, 'edit' => ['type' => 'SelectVariable']
                        ]],
                        'add' => true, 'delete' => true
                    ]
                ]],
                // Lux
                ['type' => 'ExpansionPanel', 'caption' => 'Lux (optional)', 'items' => [
                    ['type' => 'SelectVariable', 'name' => 'LuxVar', 'caption' => 'Lux-Variable (Float/Integer)'],
                    ['type' => 'Label', 'caption' => 'Den Schwellwert "Maximaler Lux-Wert" stellst du in der Instanzvariable Set_LuxMax ein.']
                ]],
                // Verhalten (Hinweis: zur Laufzeit per Instanzvariablen)
                ['type' => 'ExpansionPanel', 'caption' => 'Verhalten (Hinweis)', 'items' => [
                    ['type' => 'Label', 'caption' => 'Timeout (s), Standard-Dimmwert (%) und Max-Lux sind als Instanzvariablen direkt änderbar.']
                ]]
            ],
            'actions' => [
                ['type' => 'Label', 'caption' => '--- Debug ---'],
                ['type' => 'Button', 'caption' => 'Szene sichern (Live → Restore)', 'onClick' => 'RML_DebugStoreScene($id);'],
                ['type' => 'Button', 'caption' => 'Szene wiederherstellen',        'onClick' => 'RML_DebugRestoreScene($id);'],
                ['type' => 'Button', 'caption' => 'Szene löschen',                  'onClick' => 'RML_DebugClearScene($id);'],
                ['type' => 'Button', 'caption' => 'Auto-Off jetzt ausführen',      'onClick' => 'RML_AutoOff($id);']
            ],
            'status' => []
        ]);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message !== VM_UPDATE) return;

        // Bewegung?
        if ($SenderID === $this->ReadPropertyInteger('MotionVariable')) {
            if (!@GetValueBoolean($SenderID)) return; // nur auf TRUE
            $this->handleMotionDetected();
            return;
        }

        // Manuelles Auto-Off: wenn irgendein Licht > 0 / true wird, Timer armen
        if ($this->GetValue('Set_ManualAutoOff')) {
            foreach ($this->getLights() as $l) {
                $id  = (int)($l['VarID'] ?? 0);
                if ($SenderID === $id) {
                    if (($l['VarType'] ?? '') === 'Switch') {
                        if (@GetValueBoolean($id)) $this->armAutoOffTimer();
                    } else { // Dimmer
                        $val = @GetValue($id);
                        if (is_numeric($val)) {
                            $on = $this->isNonZero($id, (float)$val);
                            if ($on) $this->armAutoOffTimer();
                        }
                    }
                    break;
                }
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

    /* ===== Kernlogik ===== */

    private function handleMotionDetected(): void
    {
        // Stati prüfen
        if ($this->GetValue('Override')) return;
        foreach ($this->getIDList('GlobalInhibit') as $vid) { if (@GetValueBoolean($vid)) return; }
        foreach ($this->getIDList('RoomInhibit')   as $vid) { if (@GetValueBoolean($vid)) return; }

        // Lux-Grenze (optional)
        $luxVar = $this->ReadPropertyInteger('LuxVar');
        if ($luxVar > 0 && @IPS_VariableExists($luxVar)) {
            $lux = @GetValue($luxVar);
            $max = (int)$this->GetValue('Set_LuxMax');
            if (is_numeric($lux) && $max > 0 && (float)$lux > $max) return;
        }

        // Szene vorhanden? -> wiederherstellen, sonst Default schalten
        $scene = json_decode($this->ReadAttributeString('SceneRestore'), true);
        if (is_array($scene) && !empty($scene)) {
            $this->restoreScene($scene);
        } else {
            $this->switchLightsOn();
        }

        $this->armAutoOffTimer();
    }

    private function switchLightsOn(): void
    {
        $lights = $this->getLights();
        $dimPct = (int)$this->GetValue('Set_DefaultDim');

        foreach ($lights as $light) {
            $id = (int)($light['VarID'] ?? 0);
            if (!$id || !@IPS_VariableExists($id)) continue;

            $type = $light['VarType'] ?? 'Dimmer';
            if ($type === 'Switch') {
                @RequestAction($id, true);
            } else { // Dimmer
                $target = $this->pctToRaw($id, $dimPct);
                @RequestAction($id, $target);
            }
        }
    }

    public function AutoOff(): void
    {
        // Szene sichern
        $this->storeCurrentScene();

        // Alles aus
        foreach ($this->getLights() as $light) {
            $id = (int)($light['VarID'] ?? 0);
            if (!$id || !@IPS_VariableExists($id)) continue;

            $type = $light['VarType'] ?? 'Dimmer';
            if ($type === 'Switch') {
                @RequestAction($id, false);
            } else { // Dimmer
                @RequestAction($id, $this->pctToRaw($id, 0));
            }
        }
        $this->SetTimerInterval('AutoOff', 0);
    }

    /* ===== Helpers ===== */

    // Konvertiert Prozent (0..100) in Rohwert entsprechend Profil (0..100 oder 0..255)
    private function pctToRaw(int $varID, int $pct): int
    {
        $pct = max(0, min(100, $pct));
        $v = IPS_GetVariable($varID);
        $pname = $v['VariableCustomProfile'] ?: $v['VariableProfile'];
        if ($pname === '') return $pct;
        $prof = IPS_GetVariableProfile($pname);
        $max  = (float)($prof['MaxValue'] ?? 100);
        return (int)round($pct * $max / 100.0);
    }

    // prüft bei Dimmern, ob "an" (roh != 0)
    private function isNonZero(int $varID, float $raw): bool
    {
        $v = IPS_GetVariable($varID);
        $pname = $v['VariableCustomProfile'] ?: $v['VariableProfile'];
        if ($pname === '') return ($raw > 0.0);
        $prof = IPS_GetVariableProfile($pname);
        $min  = (float)($prof['MinValue'] ?? 0);
        return ($raw > $min);
    }

    private function getLights(): array
    {
        $arr = @json_decode($this->ReadPropertyString('Lights'), true);
        return is_array($arr) ? $arr : [];
    }

    // akzeptiert sowohl [1,2,3] als auch [{var:1},{var:2}]
    private function getIDList(string $prop): array
    {
        $raw = @json_decode($this->ReadPropertyString($prop), true);
        if (!is_array($raw)) return [];
        $ids = [];
        foreach ($raw as $row) {
            if (is_array($row) && isset($row['var'])) $ids[] = (int)$row['var'];
            else                                      $ids[] = (int)$row;
        }
        // nur existierende Variablen zurückgeben
        return array_values(array_unique(array_filter($ids, fn($id) => $id > 0 && @IPS_VariableExists($id))));
    }

    private function storeCurrentScene(): void
    {
        $scene = [];
        foreach ($this->getLights() as $light) {
            $id = (int)($light['VarID'] ?? 0);
            if (!$id || !@IPS_VariableExists($id)) continue;
            $scene[(string)$id] = @GetValue($id);
        }
        $this->WriteAttributeString('SceneRestore', json_encode($scene));
    }

    private function restoreScene(array $scene): void
    {
        foreach ($scene as $id => $val) {
            $vid = (int)$id;
            if (!@IPS_VariableExists($vid)) continue;
            @RequestAction($vid, $val);
        }
        $this->WriteAttributeString('SceneRestore', json_encode([]));
    }

    /* ===== Debug-Buttons (Wrapper) ===== */
    public function DebugStoreScene(): void
    {
        $this->WriteAttributeString('SceneRestore', json_encode($this->captureCurrentScene()));
        $this->SendDebug('DebugStoreScene', 'Scene stored', 0);
    }
    public function DebugRestoreScene(): void
    {
        $scene = @json_decode($this->ReadAttributeString('SceneRestore'), true) ?: [];
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
        $scene = [];
        foreach ($this->getLights() as $light) {
            $id = (int)($light['VarID'] ?? 0);
            if ($id > 0 && @IPS_VariableExists($id)) {
                $scene[(string)$id] = @GetValue($id);
            }
        }
        return $scene;
    }

    /* ===== Öffentliche Komfort-Wrapper: erzeugen RML_* ===== */
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