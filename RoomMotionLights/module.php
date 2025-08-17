<?php
declare(strict_types=1);

class RoomMotionLights extends IPSModule
{
    /* ====== Konstanten ====== */
    private const VM_UPDATE = 10603; // VariableManager: Update

    /* ====== Lifecycle ====== */
    public function Create()
    {
        parent::Create();

        // Properties
        $this->RegisterPropertyString ('MotionVars', '[]');       // Liste (List) -> [{var:int}]
        $this->RegisterPropertyString ('InhibitVars', '[]');      // Liste (List) -> [{var:int}]
        $this->RegisterPropertyString ('GlobalInhibits', '[]');   // Liste (List) -> [{var:int}]
        $this->RegisterPropertyString ('Lights', '[]');           // Liste (List) -> [{type, var, switchVar, range}]
        $this->RegisterPropertyInteger('TimeoutSec', 60);         // Fallback (Sek.)
        $this->RegisterPropertyInteger('TimeoutVar', 0);          // VariableID (optional, überschreibt TimeoutSec)
        $this->RegisterPropertyInteger('DefaultDim', 60);         // % für Dimmer
        $this->RegisterPropertyBoolean('ManualAutoOff', true);    // Timer auch bei manuellem Einschalten
        $this->RegisterPropertyInteger('LuxVar', 0);              // optional
        $this->RegisterPropertyInteger('LuxMax', 50);             // optional

        // States/Timer
        $this->RegisterVariableBoolean('Override', 'Automatik (Auto-ON) deaktivieren', '~Switch', 1);
        $this->RegisterTimer('AutoOff', 0, 'RML_AutoOff($_IPS[\'TARGET\']);');

        // Attribute: gemerkte Registrierungen für MessageSink
        $this->RegisterAttributeString('RegisteredIDs', '[]');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Vorherige Registrierungen sauber lösen
        $prev = $this->getRegisteredIDs();
        foreach ($prev as $id) {
            if (@IPS_ObjectExists($id)) {
                @$this->UnregisterMessage($id, self::VM_UPDATE);
            }
        }

        $new = [];

        // Bewegungsmelder registrieren
        foreach ($this->getMotionVars() as $vid) {
            $this->RegisterMessage($vid, self::VM_UPDATE);
            $new[] = $vid;
        }
        // Inhibits registrieren
        foreach ($this->getInhibitVars() as $vid) {
            $this->RegisterMessage($vid, self::VM_UPDATE);
            $new[] = $vid;
        }
        foreach ($this->getGlobalInhibits() as $vid) {
            $this->RegisterMessage($vid, self::VM_UPDATE);
            $new[] = $vid;
        }
        // Lichter (Dimmer/Schalter) registrieren (für manuelles Auto-Off)
        foreach ($this->getLights() as $a) {
            $v  = (int)($a['var'] ?? 0);
            $sv = (int)($a['switchVar'] ?? 0);
            if ($v  > 0 && @IPS_VariableExists($v))  { $this->RegisterMessage($v,  self::VM_UPDATE); $new[] = $v; }
            if ($sv > 0 && @IPS_VariableExists($sv)) { $this->RegisterMessage($sv, self::VM_UPDATE); $new[] = $sv; }
        }

        $this->setRegisteredIDs($new);
    }

    /* ====== Formular ====== */
    public function GetConfigurationForm(): string
    {
        return json_encode([
            'elements' => [
                // Bewegungsmelder als List (robust, kein multiple-Select mehr)
                ['type' => 'ExpansionPanel', 'caption' => 'Bewegungsmelder', 'items' => [
                    [
                        'type' => 'List', 'name' => 'MotionVars', 'caption' => 'Melder',
                        'columns' => [[
                            'caption' => 'Variable', 'name' => 'var', 'width' => '320px',
                            'add' => 0, 'edit' => ['type' => 'SelectVariable']
                        ]],
                        'add' => true, 'delete' => true
                    ]
                ]],
                // Lichter
                ['type' => 'ExpansionPanel', 'caption' => 'Lichter', 'items' => [
                    [
                        'type' => 'List', 'name' => 'Lights', 'caption' => 'Akteure',
                        'columns' => [
                            [
                                'caption' => 'Typ', 'name' => 'type', 'width' => '120px', 'add' => 'dimmer',
                                'edit' => [
                                    'type' => 'Select',
                                    'options' => [
                                        ['caption' => 'Dimmer',  'value' => 'dimmer'],
                                        ['caption' => 'Schalter','value' => 'switch']
                                    ]
                                ]
                            ],
                            [
                                'caption' => 'Helligkeitsvariable', 'name' => 'var', 'width' => '260px', 'add' => 0,
                                'edit' => ['type' => 'SelectVariable']
                            ],
                            [
                                'caption' => 'Ein/Aus/Status-Variable (optional)', 'name' => 'switchVar', 'width' => '260px', 'add' => 0,
                                'edit' => ['type' => 'SelectVariable']
                            ],
                            [
                                'caption' => 'Range', 'name' => 'range', 'width' => '160px', 'add' => 'auto',
                                'edit' => [
                                    'type' => 'Select',
                                    'options' => [
                                        ['caption' => 'auto (aus Profil)', 'value' => 'auto'],
                                        ['caption' => '0..100',            'value' => '0..100'],
                                        ['caption' => '0..255',            'value' => '0..255']
                                    ]
                                ]
                            ]
                        ],
                        'add' => true, 'delete' => true
                    ]
                ]],
                // Stati (Inhibits)
                ['type' => 'ExpansionPanel', 'caption' => 'Stati (Inhibits)', 'items' => [
                    [
                        'type' => 'List', 'name' => 'InhibitVars', 'caption' => 'Raum-Stati',
                        'columns' => [[
                            'caption' => 'Variable', 'name' => 'var', 'width' => '320px',
                            'add' => 0, 'edit' => ['type' => 'SelectVariable']
                        ]],
                        'add' => true, 'delete' => true
                    ],
                    [
                        'type' => 'List', 'name' => 'GlobalInhibits', 'caption' => 'Globale Stati',
                        'columns' => [[
                            'caption' => 'Variable', 'name' => 'var', 'width' => '320px',
                            'add' => 0, 'edit' => ['type' => 'SelectVariable']
                        ]],
                        'add' => true, 'delete' => true
                    ]
                ]],
                // Lux (optional)
                ['type' => 'ExpansionPanel', 'caption' => 'Lux (optional)', 'items' => [
                    ['type' => 'SelectVariable', 'name' => 'LuxVar', 'caption' => 'Lux-Variable'],
                    ['type' => 'NumberSpinner',  'name' => 'LuxMax', 'caption' => 'Lux max', 'minimum' => 0, 'maximum' => 100000]
                ]],
                // Verhalten
                ['type' => 'ExpansionPanel', 'caption' => 'Verhalten', 'items' => [
                    ['type' => 'NumberSpinner',  'name' => 'TimeoutSec', 'caption' => 'Timeout Standard (s)', 'minimum' => 5, 'maximum' => 3600],
                    ['type' => 'SelectVariable', 'name' => 'TimeoutVar', 'caption' => 'Timeout aus Variable (optional)'],
                    ['type' => 'NumberSpinner',  'name' => 'DefaultDim', 'caption' => 'Default Dim (%)', 'minimum' => 1, 'maximum' => 100],
                    ['type' => 'CheckBox',       'name' => 'ManualAutoOff', 'caption' => 'Manuelles Auto-Off aktiv']
                ]]
            ],
            'actions' => [
                ['type' => 'Button', 'caption' => 'Test: Auto-Off jetzt', 'onClick' => 'RML_AutoOff($id);']
            ],
            'status' => []
        ]);
    }

    /* ====== Timer: Auto-Off ====== */
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

    /* ====== MessageSink ====== */
    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message !== self::VM_UPDATE) return;

        // 1) Bewegungsmelder?
        if (in_array($SenderID, $this->getMotionVars(), true)) {
            // Nur auf TRUE reagieren
            if (!@GetValueBoolean($SenderID)) return;

            // Blocker: Override?
            if ($this->GetValue('Override')) return;

            // Blocker: Inhibits (Raum + Global)
            foreach ($this->getInhibitVars() as $vid) {
                if (@GetValueBoolean($vid)) return;
            }
            foreach ($this->getGlobalInhibits() as $vid) {
                if (@GetValueBoolean($vid)) return;
            }

            // Lux optional
            $luxVar = $this->ReadPropertyInteger('LuxVar');
            if ($luxVar > 0 && @IPS_VariableExists($luxVar)) {
                $lux = @GetValue($luxVar);
                if (is_numeric($lux) && $lux > $this->ReadPropertyInteger('LuxMax')) {
                    return;
                }
            }

            // Einschalten (DefaultDim % für Dimmer, Switch ON)
            $defaultPct = (int)$this->ReadPropertyInteger('DefaultDim');
            foreach ($this->getLights() as $a) {
                $type = $a['type'] ?? '';
                if ($type === 'switch') {
                    $this->setSwitch((int)$a['var'], true);
                } elseif ($type === 'dimmer') {
                    $this->setDimmerPct($a, $defaultPct);
                }
            }

            // Timer (retriggert bei jeder Bewegung)
            $this->armAutoOffTimer();
            return;
        }

        // 2) Manuelle Änderungen an Lichtern → optional Auto-Off starten
        if ($this->ReadPropertyBoolean('ManualAutoOff')) {
            foreach ($this->getLights() as $a) {
                $v  = (int)($a['var'] ?? 0);
                $sv = (int)($a['switchVar'] ?? 0);

                if ($SenderID === $v) {
                    if (($a['type'] ?? '') === 'switch') {
                        if (@GetValueBoolean($v)) $this->armAutoOffTimer();
                    } elseif (($a['type'] ?? '') === 'dimmer') {
                        $pct = $this->getDimmerPct($a);
                        if ($pct > 0) $this->armAutoOffTimer();
                    }
                }
                if ($sv > 0 && $SenderID === $sv && @GetValueBoolean($sv)) {
                    $this->armAutoOffTimer();
                }
            }
        }
    }

    /* ====== Helper: Properties lesen ====== */

    private function getVarListFromProperty(string $propName): array
    {
        $raw = json_decode($this->ReadPropertyString($propName), true);
        $ids = [];
        if (is_array($raw)) {
            foreach ($raw as $row) {
                if (is_array($row) && isset($row['var'])) {
                    $ids[] = (int)$row['var'];   // neues Listenformat
                } else {
                    $ids[] = (int)$row;          // Fallback: altes multiple[]-Format
                }
            }
        }
        // nur existierende Variablen behalten
        $ids = array_values(array_unique(array_filter($ids, fn($id) => $id > 0 && @IPS_VariableExists($id))));
        return $ids;
    }

    private function getMotionVars(): array      { return $this->getVarListFromProperty('MotionVars'); }
    private function getInhibitVars(): array     { return $this->getVarListFromProperty('InhibitVars'); }
    private function getGlobalInhibits(): array  { return $this->getVarListFromProperty('GlobalInhibits'); }

    private function getLights(): array
    {
        $arr = json_decode($this->ReadPropertyString('Lights'), true);
        return is_array($arr) ? $arr : [];
    }

    /* ====== Helper: Timer/Auto-Off ====== */
    private function armAutoOffTimer(): void
    {
        $timeout = (int)$this->ReadPropertyInteger('TimeoutSec');
        $tVar    = (int)$this->ReadPropertyInteger('TimeoutVar');
        if ($tVar > 0 && @IPS_VariableExists($tVar)) {
            $val = @GetValue($tVar);
            if (is_numeric($val) && (int)$val > 0) $timeout = (int)$val;
        }
        $this->SetTimerInterval('AutoOff', max(0, $timeout) * 1000);
    }

    /* ====== Helper: Dimmer/Switch ====== */

    private function setSwitch(int $varID, bool $state): void
    {
        if ($varID <= 0 || !@IPS_VariableExists($varID)) return;
        @RequestAction($varID, $state);
    }

    private function getDimmerPct(array $actor): int
    {
        $varID = (int)($actor['var'] ?? 0);
        if ($varID <= 0 || !@IPS_VariableExists($varID)) return 0;

        $range = $this->effectiveRange($actor);
        $raw   = @GetValue($varID);
        $rawF  = is_string($raw) ? floatval(str_replace(',', '.', $raw)) : (float)$raw;

        if ($range === '0..255') {
            $pct = (int)round(($rawF / 255.0) * 100.0);
        } else {
            if ($rawF > 0.0 && $rawF <= 1.0) $rawF *= 100.0; // 0..1 → 0..100
            $pct = (int)round($rawF);
        }
        return max(0, min(100, $pct));
    }

    private function setDimmerPct(array $actor, int $pct): void
    {
        $pct   = max(0, min(100, $pct));
        $varID = (int)($actor['var'] ?? 0);
        if ($varID <= 0 || !@IPS_VariableExists($varID)) return;

        $range = $this->effectiveRange($actor);

        // optional: separate Ein/Aus Variable schalten
        $sv = (int)($actor['switchVar'] ?? 0);
        if ($pct > 0 && $sv > 0 && @IPS_VariableExists($sv)) {
            @RequestAction($sv, true);
        }

        if ($range === '0..255') {
            $val = (int)round($pct * 255 / 100);
            @RequestAction($varID, $val);
        } else {
            @RequestAction($varID, $pct);
        }

        if ($pct === 0 && $sv > 0 && @IPS_VariableExists($sv)) {
            @RequestAction($sv, false);
        }
    }

    /* ====== Helper: Range-Erkennung (generisch, aus Profil-Min/Max) ====== */

    private function effectiveRange(array $actor): string
    {
        $r = (string)($actor['range'] ?? 'auto');
        if ($r === '' || $r === 'auto') {
            return $this->detectRangeFromProfile((int)($actor['var'] ?? 0));
        }
        return $r;
    }

    private function detectRangeFromProfile(int $varID): string
    {
        if ($varID <= 0 || !@IPS_VariableExists($varID)) return '0..100';
        $v = IPS_GetVariable($varID);
        $profName = $v['VariableCustomProfile'] ?: $v['VariableProfile'];
        if ($profName === '') return '0..100';
        $p = IPS_GetVariableProfile($profName);
        $max = (float)($p['MaxValue'] ?? 100.0);
        return ($max > 100.0) ? '0..255' : '0..100';
    }

    /* ====== Helper: Registrierungen merken ====== */

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
}