<?php
declare(strict_types=1);

class RoomMotionLights extends IPSModule
{
    // --- Konstanten für MessageSink ---
    private const VM_UPDATE = 10603;

    public function Create()
    {
        parent::Create();

        // --- Properties ---
        $this->RegisterPropertyString('MotionVars', '[]');      // int[]
        $this->RegisterPropertyString('Lights', '[]');          // [{type, var, range, switchVar}]
        $this->RegisterPropertyString('InhibitVars', '[]');     // int[]
        $this->RegisterPropertyString('GlobalInhibits', '[]');  // int[]
        $this->RegisterPropertyInteger('TimeoutSec', 60);       // s (Fallback)
        $this->RegisterPropertyInteger('TimeoutVar', 0);        // optional: VariableID
        $this->RegisterPropertyInteger('DefaultDim', 60);       // %
        $this->RegisterPropertyBoolean('ManualAutoOff', true);  // Timer auch bei manuellem ON
        $this->RegisterPropertyInteger('LuxVar', 0);            // optional Lux Variable
        $this->RegisterPropertyInteger('LuxMax', 50);           // Schwelle

        // --- Instanz-Variablen / Timer ---
        $this->RegisterVariableBoolean('Override', 'Automatik (Auto-ON) deaktivieren', '~Switch', 1);
        $this->RegisterTimer('AutoOff', 0, 'RML_AutoOff($_IPS[\'TARGET\']);');

        $this->RegisterAttributeString('RegisteredIDs', '[]'); // merkt registrierte Message-IDs
    }

public function ApplyChanges()
{
    parent::ApplyChanges();

    // alte Registrierungen sauber entfernen
    $prev = $this->getRegisteredIDs();
    foreach ($prev as $id) {
        if (IPS_VariableExists($id)) {
            @ $this->UnregisterMessage($id, self::VM_UPDATE);
        }
    }

    // neue Registrierungen aufbauen
    $newIDs = [];

    // Motion-Variablen
    foreach ($this->getMotionVars() as $vid) {
        if (IPS_VariableExists($vid)) {
            $this->RegisterMessage($vid, self::VM_UPDATE);
            $newIDs[] = $vid;
        }
    }

    // Licht-Variablen (Helligkeit/Schalter)
    foreach ($this->getLights() as $a) {
        $v = (int)($a['var'] ?? 0);
        if ($v > 0 && IPS_VariableExists($v)) {
            $this->RegisterMessage($v, self::VM_UPDATE);
            $newIDs[] = $v;
        }
        $sv = (int)($a['switchVar'] ?? 0);
        if ($sv > 0 && IPS_VariableExists($sv)) {
            $this->RegisterMessage($sv, self::VM_UPDATE);
            $newIDs[] = $sv;
        }
    }

    // (optional) TimeoutVar überwachen? normalerweise nicht nötig für MessageSink
    // $tVar = $this->ReadPropertyInteger('TimeoutVar');
    // if ($tVar > 0 && IPS_VariableExists($tVar)) { ... }

    // Liste speichern, damit wir sie beim nächsten ApplyChanges wieder deregistrieren können
    $this->setRegisteredIDs($newIDs);
}
    // === Config-Form (einfach) ===
public function GetConfigurationForm(): string
{
    return json_encode([
        'elements' => [
            ['type' => 'ExpansionPanel', 'caption' => 'Bewegungsmelder', 'items' => [
                ['type' => 'SelectVariable', 'name' => 'MotionVars', 'caption' => 'Melder (mehrfach)', 'multiple' => true]
            ]],
            ['type' => 'ExpansionPanel', 'caption' => 'Lichter', 'items' => [
                [
                    'type' => 'List',
                    'name' => 'Lights',
                    'caption' => 'Akteure',
                    'columns' => [
                        [
                            'caption' => 'Typ',
                            'name'    => 'type',
                            'width'   => '120px',
                            'add'     => 'dimmer',
                            'edit'    => [
                                'type'    => 'Select',
                                'options' => [
                                    ['caption' => 'Dimmer',  'value' => 'dimmer'],
                                    ['caption' => 'Schalter','value' => 'switch']
                                ]
                            ]
                        ],
                        [
                            'caption' => 'Variable',
                            'name'    => 'var',
                            'width'   => '250px',
                            'add'     => 0,                       // <-- wichtig
                            'edit'    => ['type' => 'SelectVariable']
                        ],
                        [
                            'caption' => 'SwitchVar (optional)',
                            'name'    => 'switchVar',
                            'width'   => '220px',
                            'add'     => 0,                       // <-- wichtig
                            'edit'    => ['type' => 'SelectVariable']
                        ],
                        [
                            'caption' => 'Range',
                            'name'    => 'range',
                            'width'   => '120px',
                            'add'     => '0..100',
                            'edit'    => [
                                'type'    => 'Select',
                                'options' => [
                                    ['caption' => '0..100', 'value' => '0..100'],
                                    ['caption' => '0..255', 'value' => '0..255']
                                ]
                            ]
                        ]
                    ],
                    'add'    => true,
                    'delete' => true
                ]
            ]],
            ['type' => 'ExpansionPanel', 'caption' => 'Stati (Inhibits)', 'items' => [
                ['type' => 'SelectVariable', 'name' => 'InhibitVars',   'caption' => 'Raum-Stati',    'multiple' => true],
                ['type' => 'SelectVariable', 'name' => 'GlobalInhibits','caption' => 'Globale Stati', 'multiple' => true]
            ]],
            ['type' => 'ExpansionPanel', 'caption' => 'Lux (optional)', 'items' => [
                ['type' => 'SelectVariable', 'name' => 'LuxVar', 'caption' => 'Lux-Variable'],
                ['type' => 'NumberSpinner',  'name' => 'LuxMax', 'caption' => 'Lux max', 'minimum' => 0, 'maximum' => 100000]
            ]],
            ['type' => 'ExpansionPanel', 'caption' => 'Verhalten', 'items' => [
                ['type' => 'NumberSpinner', 'name' => 'TimeoutSec', 'caption' => 'Timeout Standard (s)', 'minimum' => 5, 'maximum' => 3600],
                ['type' => 'SelectVariable','name' => 'TimeoutVar', 'caption' => 'Timeout aus Variable (optional)'],
                ['type' => 'NumberSpinner', 'name' => 'DefaultDim', 'caption' => 'Default Dim (%)', 'minimum' => 1, 'maximum' => 100],
                ['type' => 'CheckBox',      'name' => 'ManualAutoOff', 'caption' => 'Manuelles Auto-Off aktiv']
            ]]
        ],
        'actions' => [
            ['type' => 'Button', 'caption' => 'Test: Auto-Off jetzt', 'onClick' => 'RML_AutoOff($id);']
        ],
        'status' => []
    ]);
}

    // === Timer-Callback ===
    public function AutoOff(): void
    {
        foreach ($this->getLights() as $a) {
            $type = $a['type'] ?? '';
            if ($type === 'switch') {
                $this->setSwitch((int)$a['var'], false);
            } elseif ($type === 'dimmer') {
                $this->setDimmerPct($a, 0);
            }
        }
        $this->SetTimerInterval('AutoOff', 0);
    }

    // === MessageSink: reagiert auf Motion + manuelle Licht-Änderungen ===
    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message !== self::VM_UPDATE) {
            return;
        }

        // 1) Motion?
        if (in_array($SenderID, $this->getMotionVars(), true)) {
            // Bei Bewegung nur auf TRUE reagieren
            if (!@GetValueBoolean($SenderID)) {
                return;
            }
            // Blocker prüfen
            if ($this->isMotionBlocked()) {
                return;
            }
            // Lux prüfen (optional)
            $luxVar = $this->ReadPropertyInteger('LuxVar');
            if ($luxVar > 0 && IPS_VariableExists($luxVar)) {
                $lux = @GetValue($luxVar);
                if (is_numeric($lux) && $lux > $this->ReadPropertyInteger('LuxMax')) {
                    return;
                }
            }
            // Einschalten
            $defaultPct = $this->ReadPropertyInteger('DefaultDim');
            foreach ($this->getLights() as $a) {
                $type = $a['type'] ?? '';
                if ($type === 'switch') {
                    $this->setSwitch((int)$a['var'], true);
                } elseif ($type === 'dimmer') {
                    $this->setDimmerPct($a, $defaultPct);
                }
            }
            // Timer setzen
            $this->armAutoOffTimer();
            return;
        }

        // 2) Manuelle Lichtänderungen → optional Auto-Off
        if ($this->ReadPropertyBoolean('ManualAutoOff')) {
            foreach ($this->getLights() as $a) {
                $var = (int)($a['var'] ?? 0);
                if ($var <= 0) {
                    continue;
                }
                if ($SenderID === $var) {
                    if (($a['type'] ?? '') === 'switch') {
                        // nur bei TRUE starten
                        if (@GetValueBoolean($var)) {
                            $this->armAutoOffTimer();
                        }
                    } elseif (($a['type'] ?? '') === 'dimmer') {
                        // bei >0% starten
                        $pct = $this->getDimmerPct($a);
                        if ($pct > 0) {
                            $this->armAutoOffTimer();
                        }
                    }
                }
                // optional: separate switchVar überwachen
                $sv = (int)($a['switchVar'] ?? 0);
                if ($sv > 0 && $SenderID === $sv && @GetValueBoolean($sv)) {
                    $this->armAutoOffTimer();
                }
            }
        }
    }

    /* ================= Helper ================= */
private function getRegisteredIDs(): array
{
    $raw = $this->ReadAttributeString('RegisteredIDs');
    $arr = @json_decode($raw, true);
    return is_array($arr) ? array_map('intval', $arr) : [];
}

private function setRegisteredIDs(array $ids): void
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $this->WriteAttributeString('RegisteredIDs', json_encode($ids));
}

    private function getMotionVars(): array
    {
        $arr = json_decode($this->ReadPropertyString('MotionVars'), true);
        return is_array($arr) ? array_map('intval', $arr) : [];
    }

    private function getInhibits(): array
    {
        $r = json_decode($this->ReadPropertyString('InhibitVars'), true);
        $g = json_decode($this->ReadPropertyString('GlobalInhibits'), true);
        $r = is_array($r) ? array_map('intval', $r) : [];
        $g = is_array($g) ? array_map('intval', $g) : [];
        return array_values(array_unique(array_merge($r, $g)));
    }

    private function getLights(): array
    {
        $arr = json_decode($this->ReadPropertyString('Lights'), true);
        return is_array($arr) ? $arr : [];
    }

    private function isMotionBlocked(): bool
    {
        // Override
        if ($this->GetValue('Override')) {
            return true;
        }
        // Stati (global/raum)
        foreach ($this->getInhibits() as $vid) {
            if (IPS_VariableExists($vid) && @GetValueBoolean($vid)) {
                return true;
            }
        }
        return false;
    }

    private function armAutoOffTimer(): void
    {
        $timeout = (int)$this->ReadPropertyInteger('TimeoutSec');
        $tVar    = (int)$this->ReadPropertyInteger('TimeoutVar');
        if ($tVar > 0 && IPS_VariableExists($tVar)) {
            $val = @GetValue($tVar);
            if (is_numeric($val) && (int)$val > 0) {
                $timeout = (int)$val;
            }
        }
        $this->SetTimerInterval('AutoOff', max(0, $timeout) * 1000);
    }

    private function setSwitch(int $varID, bool $state): void
    {
        if ($varID <= 0 || !IPS_VariableExists($varID)) {
            return;
        }
        @RequestAction($varID, $state);
    }

    private function getDimmerPct(array $actor): int
    {
        $varID = (int)($actor['var'] ?? 0);
        if ($varID <= 0 || !IPS_VariableExists($varID)) {
            return 0;
        }
        $range = (string)($actor['range'] ?? '0..100');
        $raw   = @GetValue($varID);
        $rawF  = is_string($raw) ? floatval(str_replace(',', '.', $raw)) : (float)$raw;

        if ($range === '0..255') {
            $pct = (int)round(($rawF / 255.0) * 100.0);
        } else {
            // 0..100 (evtl. 0..1 abfangen)
            if ($rawF > 0.0 && $rawF <= 1.0) {
                $rawF *= 100.0;
            }
            $pct = (int)round($rawF);
        }
        return max(0, min(100, $pct));
    }

    private function setDimmerPct(array $actor, int $pct): void
    {
        $pct = max(0, min(100, $pct));
        $varID = (int)($actor['var'] ?? 0);
        if ($varID <= 0 || !IPS_VariableExists($varID)) {
            return;
        }
        $range = (string)($actor['range'] ?? '0..100');

        // optional separate Schaltvariable
        if ($pct > 0 && !empty($actor['switchVar']) && IPS_VariableExists((int)$actor['switchVar'])) {
            @RequestAction((int)$actor['switchVar'], true);
        }

        if ($range === '0..255') {
            $val = (int)round($pct * 255 / 100);
            @RequestAction($varID, $val);
        } else {
            @RequestAction($varID, $pct);
        }

        if ($pct === 0 && !empty($actor['switchVar']) && IPS_VariableExists((int)$actor['switchVar'])) {
            @RequestAction((int)$actor['switchVar'], false);
        }
    }

    
}