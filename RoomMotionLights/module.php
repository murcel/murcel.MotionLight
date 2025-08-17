<?php
declare(strict_types=1);

class RoomMotionLights extends IPSModule
{
    private const VM_UPDATE = 10603; // VariableManager: Update

    /* ================= Lifecycle ================= */
    public function Create()
    {
        parent::Create();

        // ---- Properties (Fallbacks / Konfig per Formular) ----
        $this->RegisterPropertyString ('MotionVars', '[]');       // [{var:int}]
        $this->RegisterPropertyString ('InhibitVars', '[]');      // [{var:int}]
        $this->RegisterPropertyString ('GlobalInhibits', '[]');   // [{var:int}]
        $this->RegisterPropertyString ('Lights', '[]');           // [{type, var, switchVar, range}]
        $this->RegisterPropertyInteger('TimeoutSec', 60);
        $this->RegisterPropertyInteger('TimeoutVar', 0);
        $this->RegisterPropertyInteger('DefaultDim', 60);
        $this->RegisterPropertyBoolean('ManualAutoOff', true);
        $this->RegisterPropertyInteger('LuxVar', 0);
        $this->RegisterPropertyInteger('LuxMax', 50);

        // ---- Laufzeit-Settings als steuerbare Variablen (für View) ----
        $this->ensureProfiles();
        $this->RegisterVariableInteger('Set_TimeoutSec', 'Timeout (s)', 'RML.TimeoutSec', 2);
        $this->RegisterVariableInteger('Set_DefaultDim', 'Default Dim (%)', '~Intensity.100', 3);
        $this->RegisterVariableInteger('Set_LuxMax', 'Lux max', 'RML.LuxMax', 4);
        $this->RegisterVariableBoolean('Set_ManualAutoOff', 'Manuelles Auto-Off aktiv', '~Switch', 5);
        $this->EnableAction('Set_TimeoutSec');
        $this->EnableAction('Set_DefaultDim');
        $this->EnableAction('Set_LuxMax');
        $this->EnableAction('Set_ManualAutoOff');

        // Erstwerte aus Properties setzen (nur beim ersten Anlegen sind die Variablen 0/uninitialized)
        if ($this->GetValue('Set_TimeoutSec') === 0) {
            @SetValueInteger($this->GetIDForIdent('Set_TimeoutSec'), max(5, (int)$this->ReadPropertyInteger('TimeoutSec')));
        }
        if ($this->GetValue('Set_DefaultDim') === 0) {
            @SetValueInteger($this->GetIDForIdent('Set_DefaultDim'), max(1, (int)$this->ReadPropertyInteger('DefaultDim')));
        }
        if ($this->GetValue('Set_LuxMax') === 0) {
            @SetValueInteger($this->GetIDForIdent('Set_LuxMax'), max(0, (int)$this->ReadPropertyInteger('LuxMax')));
        }
        // ManualAutoOff: nur setzen, wenn Var noch nicht existierte (ansonsten User-Wert respektieren)
        if (!IPS_VariableExists(@$this->GetIDForIdent('Set_ManualAutoOff')) || $this->GetValue('Set_ManualAutoOff') === false) {
            @SetValueBoolean($this->GetIDForIdent('Set_ManualAutoOff'), (bool)$this->ReadPropertyBoolean('ManualAutoOff'));
        }

        // ---- Sonstige States/Timer ----
        $this->RegisterVariableBoolean('Override', 'Automatik (Auto-ON) deaktivieren', '~Switch', 1);
        $this->RegisterTimer('AutoOff', 0, 'RML_AutoOff($_IPS[\'TARGET\']);');

        // Registrierte Message-IDs merken
        $this->RegisterAttributeString('RegisteredIDs', '[]');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Profile sicherstellen, falls durch Update neu gestartet
        $this->ensureProfiles();

        // Vorherige Registrierungen lösen
        $prev = $this->getRegisteredIDs();
        foreach ($prev as $id) {
            if (@IPS_ObjectExists($id)) {
                @$this->UnregisterMessage($id, self::VM_UPDATE);
            }
        }
        $new = [];

        // Bewegungsmelder
        foreach ($this->getMotionVars() as $vid) {
            $this->RegisterMessage($vid, self::VM_UPDATE);
            $new[] = $vid;
        }
        // Inhibits (Raum + global)
        foreach ($this->getInhibitVars() as $vid) {
            $this->RegisterMessage($vid, self::VM_UPDATE);
            $new[] = $vid;
        }
        foreach ($this->getGlobalInhibits() as $vid) {
            $this->RegisterMessage($vid, self::VM_UPDATE);
            $new[] = $vid;
        }
        // Lichter (manuelles Auto-Off)
        foreach ($this->getLights() as $a) {
            $v  = (int)($a['var'] ?? 0);
            $sv = (int)($a['switchVar'] ?? 0);
            if ($v  > 0 && @IPS_VariableExists($v))  { $this->RegisterMessage($v,  self::VM_UPDATE); $new[] = $v; }
            if ($sv > 0 && @IPS_VariableExists($sv)) { $this->RegisterMessage($sv, self::VM_UPDATE); $new[] = $sv; }
        }
        $this->setRegisteredIDs($new);
    }

    /* ================= Formular ================= */
    public function GetConfigurationForm(): string
    {
        return json_encode([
            'elements' => [
                ['type' => 'ExpansionPanel', 'caption' => 'Bewegungsmelder', 'items' => [[
                    'type' => 'List', 'name' => 'MotionVars', 'caption' => 'Melder',
                    'columns' => [[
                        'caption' => 'Variable', 'name' => 'var', 'width' => '320px',
                        'add' => 0, 'edit' => ['type' => 'SelectVariable']
                    ]],
                    'add' => true, 'delete' => true
                ]]],
                ['type' => 'ExpansionPanel', 'caption' => 'Lichter', 'items' => [[
                    'type' => 'List', 'name' => 'Lights', 'caption' => 'Akteure',
                    'columns' => [
                        ['caption' => 'Typ', 'name' => 'type', 'width' => '120px', 'add' => 'dimmer',
                         'edit' => ['type' => 'Select', 'options' => [
                             ['caption' => 'Dimmer',  'value' => 'dimmer'],
                             ['caption' => 'Schalter','value' => 'switch']
                         ]]],
                        ['caption' => 'Helligkeitsvariable', 'name' => 'var', 'width' => '260px', 'add' => 0,
                         'edit' => ['type' => 'SelectVariable']],
                        ['caption' => 'Ein/Aus/Status-Variable (optional)', 'name' => 'switchVar', 'width' => '260px', 'add' => 0,
                         'edit' => ['type' => 'SelectVariable']],
                        ['caption' => 'Range', 'name' => 'range', 'width' => '160px', 'add' => 'auto',
                         'edit' => ['type' => 'Select', 'options' => [
                             ['caption' => 'auto (aus Profil)', 'value' => 'auto'],
                             ['caption' => '0..100',            'value' => '0..100'],
                             ['caption' => '0..255',            'value' => '0..255']
                         ]]]
                    ],
                    'add' => true, 'delete' => true
                ]]],
                ['type' => 'ExpansionPanel', 'caption' => 'Stati (Inhibits)', 'items' => [
                    ['type' => 'List', 'name' => 'InhibitVars', 'caption' => 'Raum-Stati',
                     'columns' => [[
                         'caption' => 'Variable', 'name' => 'var', 'width' => '320px',
                         'add' => 0, 'edit' => ['type' => 'SelectVariable']
                     ]], 'add' => true, 'delete' => true],
                    ['type' => 'List', 'name' => 'GlobalInhibits', 'caption' => 'Globale Stati',
                     'columns' => [[
                         'caption' => 'Variable', 'name' => 'var', 'width' => '320px',
                         'add' => 0, 'edit' => ['type' => 'SelectVariable']
                     ]], 'add' => true, 'delete' => true]
                ]],
                ['type' => 'ExpansionPanel', 'caption' => 'Lux (optional)', 'items' => [
                    ['type' => 'SelectVariable', 'name' => 'LuxVar', 'caption' => 'Lux-Variable'],
                    ['type' => 'Label', 'caption' => 'Lux max kann in der Instanz als Variable "Lux max" geändert werden.']
                ]],
                ['type' => 'ExpansionPanel', 'caption' => 'Verhalten', 'items' => [
                    ['type' => 'Label', 'caption' => 'Timeout, Default Dim, Lux max & Manuelles Auto-Off sind als Instanzvariablen verfügbar.'],
                    ['type' => 'NumberSpinner',  'name' => 'TimeoutSec', 'caption' => 'Timeout Standard (s)', 'minimum' => 5, 'maximum' => 3600],
                    ['type' => 'SelectVariable', 'name' => 'TimeoutVar', 'caption' => 'Timeout aus Variable (optional)'],
                    ['type' => 'NumberSpinner',  'name' => 'DefaultDim', 'caption' => 'Default Dim (%)', 'minimum' => 1, 'maximum' => 100],
                    ['type' => 'CheckBox',       'name' => 'ManualAutoOff', 'caption' => 'Manuelles Auto-Off aktiv (Initialwert für Variable)']
                ]]
            ],
            'actions' => [
                ['type' => 'Button', 'caption' => 'Test: Auto-Off jetzt', 'onClick' => 'RML_AutoOff($id);']
            ],
            'status' => []
        ]);
    }

    /* ================= Timer ================= */
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

    /* ================= MessageSink ================= */
    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message !== self::VM_UPDATE) return;

        // 1) Bewegung?
        if (in_array($SenderID, $this->getMotionVars(), true)) {
            if (!@GetValueBoolean($SenderID)) return;      // nur auf TRUE
            if ($this->GetValue('Override')) return;       // Auto-ON blockiert

            // Inhibits
            foreach ($this->getInhibitVars() as $vid) {
                if (@GetValueBoolean($vid)) return;
            }
            foreach ($this->getGlobalInhibits() as $vid) {
                if (@GetValueBoolean($vid)) return;
            }

            // Lux-Grenze (optional)
            $luxVar = $this->ReadPropertyInteger('LuxVar');
            if ($luxVar > 0 && @IPS_VariableExists($luxVar)) {
                $lux = @GetValue($luxVar);
                if (is_numeric($lux) && $lux > $this->getSettingLuxMax()) return;
            }

            // Einschalten
            $target = $this->getSettingDefaultDim();
            foreach ($this->getLights() as $a) {
                $type = $a['type'] ?? '';
                if ($type === 'switch') {
                    $this->setSwitch((int)$a['var'], true);
                } elseif ($type === 'dimmer') {
                    $this->setDimmerPct($a, $target);
                }
            }

            // Auto-Off Timer
            $this->armAutoOffTimer();
            return;
        }

        // 2) Manuelle Lichtänderungen → optional Auto-Off
        if ($this->getSettingManualAutoOff()) {
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

    /* ================= ActionHandler (View schreibt hier rein) ================= */
    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Set_TimeoutSec':
                $val = max(5, min(3600, (int)$Value));
                SetValueInteger($this->GetIDForIdent('Set_TimeoutSec'), $val);
                // Wenn Timer läuft, neu setzen
                if ($this->GetTimerInterval('AutoOff') > 0) {
                    $this->armAutoOffTimer();
                }
                break;

            case 'Set_DefaultDim':
                $val = max(1, min(100, (int)$Value));
                SetValueInteger($this->GetIDForIdent('Set_DefaultDim'), $val);
                break;

            case 'Set_LuxMax':
                $val = max(0, (int)$Value);
                SetValueInteger($this->GetIDForIdent('Set_LuxMax'), $val);
                break;

            case 'Set_ManualAutoOff':
                SetValueBoolean($this->GetIDForIdent('Set_ManualAutoOff'), (bool)$Value);
                break;

            case 'Override':
                SetValueBoolean($this->GetIDForIdent('Override'), (bool)$Value);
                break;

            default:
                // Fallback: evtl. Direktaktionen an Licht-Variablen?
                // Wir lassen Unbekanntes in Ruhe.
                break;
        }
    }

    /* ================= Settings (lesen Variablen, fallback Properties) ================= */
    private function getSettingTimeoutSec(): int
    {
        $t = (int)@GetValueInteger($this->GetIDForIdent('Set_TimeoutSec'));
        if ($t <= 0) $t = (int)$this->ReadPropertyInteger('TimeoutSec');
        return max(5, $t);
    }

    private function getSettingDefaultDim(): int
    {
        $d = (int)@GetValueInteger($this->GetIDForIdent('Set_DefaultDim'));
        if ($d <= 0) $d = (int)$this->ReadPropertyInteger('DefaultDim');
        return max(1, min(100, $d));
    }

    private function getSettingLuxMax(): int
    {
        $l = (int)@GetValueInteger($this->GetIDForIdent('Set_LuxMax'));
        if ($l < 0) $l = (int)$this->ReadPropertyInteger('LuxMax');
        return max(0, $l);
    }

    private function getSettingManualAutoOff(): bool
    {
        // Wenn Variable existiert, hat sie Vorrang. Sonst Property.
        $id = @$this->GetIDForIdent('Set_ManualAutoOff');
        if ($id && IPS_VariableExists($id)) {
            return (bool)@GetValueBoolean($id);
        }
        return (bool)$this->ReadPropertyBoolean('ManualAutoOff');
    }

    /* ================= Helper: Profiles, Timer, Listen, Lights ================= */
    private function ensureProfiles(): void
    {
        // Timeout (s)
        if (!IPS_VariableProfileExists('RML.TimeoutSec')) {
            IPS_CreateVariableProfile('RML.TimeoutSec', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileDigits('RML.TimeoutSec', 0);
            IPS_SetVariableProfileText('RML.TimeoutSec', '', ' s');
            IPS_SetVariableProfileValues('RML.TimeoutSec', 5, 3600, 1);
        }
        // Lux max (nur Zahl)
        if (!IPS_VariableProfileExists('RML.LuxMax')) {
            IPS_CreateVariableProfile('RML.LuxMax', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileDigits('RML.LuxMax', 0);
            IPS_SetVariableProfileText('RML.LuxMax', '', ' lx');
            IPS_SetVariableProfileValues('RML.LuxMax', 0, 100000, 1);
        }
    }

    private function armAutoOffTimer(): void
    {
        // TimeoutVar (Property) überschreibt unsere Set_TimeoutSec (wie gewünscht)
        $timeout = $this->getSettingTimeoutSec();
        $tVar    = (int)$this->ReadPropertyInteger('TimeoutVar');
        if ($tVar > 0 && @IPS_VariableExists($tVar)) {
            $val = @GetValue($tVar);
            if (is_numeric($val) && (int)$val > 0) $timeout = (int)$val;
        }
        $this->SetTimerInterval('AutoOff', $timeout * 1000);
    }

    private function getVarListFromProperty(string $propName): array
    {
        $raw = json_decode($this->ReadPropertyString($propName), true);
        $ids = [];
        if (is_array($raw)) {
            foreach ($raw as $row) {
                if (is_array($row) && isset($row['var'])) {
                    $ids[] = (int)$row['var'];   // neues List-Format
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
            if ($rawF > 0.0 && $rawF <= 1.0) $rawF *= 100.0;
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

        $sv = (int)($actor['switchVar'] ?? 0);
        if ($pct > 0 && $sv > 0 && @IPS_VariableExists($sv)) @RequestAction($sv, true);

        if ($range === '0..255') {
            $val = (int)round($pct * 255 / 100);
            @RequestAction($varID, $val);
        } else {
            @RequestAction($varID, $pct);
        }

        if ($pct === 0 && $sv > 0 && @IPS_VariableExists($sv)) @RequestAction($sv, false);
    }

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

    /* ===== Registered IDs tracking ===== */
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