<?php
declare(strict_types=1);

class RoomMotionLights extends IPSModule
{
    private const VM_UPDATE = 10603; // VariableManager: Update

    /* ================= Lifecycle ================= */
    public function Create()
    {
        parent::Create();

        // ---- Properties (Form) ----
        $this->RegisterPropertyString ('MotionVars', '[]');       // [{var:int}]
        $this->RegisterPropertyString ('InhibitVars', '[]');      // [{var:int}]
        $this->RegisterPropertyString ('GlobalInhibits', '[]');   // [{var:int}]
        $this->RegisterPropertyString ('Lights', '[]');           // [{type, var, switchVar, range}]
        $this->RegisterPropertyInteger('TimeoutSec', 60);
        $this->RegisterPropertyInteger('DefaultDim', 60);
        $this->RegisterPropertyBoolean('ManualAutoOff', true);
        $this->RegisterPropertyInteger('LuxVar', 0);
        $this->RegisterPropertyInteger('LuxMax', 50);
        $this->RegisterPropertyBoolean('RestoreOnNextProp', true); // Startzustand

        // ---- Laufzeit-Settings (für View/WebFront) ----
        $this->ensureProfiles();

        $this->RegisterVariableBoolean('Override', 'Automatik (Auto-ON) deaktivieren', '~Switch', 1);
        $this->EnableAction('Override');

        $this->RegisterVariableInteger('Set_TimeoutSec',  'Timeout (s)', 'RML.TimeoutSec',  2);
        $this->EnableAction('Set_TimeoutSec');

        $this->RegisterVariableInteger('Set_DefaultDim',  'Default Dim (%)', '~Intensity.100', 3);
        $this->EnableAction('Set_DefaultDim');

        $this->RegisterVariableInteger('Set_LuxMax',      'Lux max', 'RML.LuxMax', 4);
        $this->EnableAction('Set_LuxMax');

        $this->RegisterVariableBoolean('Set_ManualAutoOff', 'Manuelles Auto-Off aktiv', '~Switch', 5);
        $this->EnableAction('Set_ManualAutoOff');

        $this->RegisterVariableBoolean('RestoreOnNext', 'Szene bei nächster Bewegung wiederherstellen', '~Switch', 6);
        $this->EnableAction('RestoreOnNext');

        // ---- Timer ----
        $this->RegisterTimer('AutoOff', 0, 'RML_AutoOff($_IPS[\'TARGET\']);');

        // Debug: Restzeit in Sekunden anzeigen
        $this->RegisterVariableInteger('CountdownSec', 'Auto-Off Restzeit (s)', 'RML.TimeoutSec', 7);
        // Sekundentick für die Anzeige (läuft nur, wenn Auto-Off aktiv ist)
        $this->RegisterTimer('CountdownTick', 0, 'RML_CountdownTick($_IPS[\'TARGET\']);');
        // Endzeitpunkt des aktuellen Auto-Off (Unix-Timestamp)
        $this->RegisterAttributeInteger('AutoOffUntil', 0);

        // ---- Attribute (intern) ----
        $this->RegisterAttributeString('RegisteredIDs', '[]');      // für MessageSink
        $this->RegisterAttributeString('MemMap',        '{}');      // pro Lampe: {var:{type:'dimmer|switch', pct:int, on:bool}}
        $this->RegisterAttributeString('SceneLive',     '[]');      // aktuelle Szene
        $this->RegisterAttributeString('SceneRestore',  '[]');      // gespeicherte Szene
        $this->RegisterAttributeBoolean('GuardInternal', false);    // interne Setzungen ignorieren
    }

    // ===== Öffentliche Komfort-Wrapper (RML_* werden generiert) =====
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
    public function SetRestoreOnNext(bool $on): void
    {
        $this->RequestAction('RestoreOnNext', $on);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->ensureProfiles();

        // --- Properties -> Runtime-Variablen spiegeln ---
        @SetValueInteger($this->GetIDForIdent('Set_TimeoutSec'),   max(5, (int)$this->ReadPropertyInteger('TimeoutSec')));
        @SetValueInteger($this->GetIDForIdent('Set_DefaultDim'),   max(1, min(100, (int)$this->ReadPropertyInteger('DefaultDim'))));
        @SetValueInteger($this->GetIDForIdent('Set_LuxMax'),       max(0, (int)$this->ReadPropertyInteger('LuxMax')));
        @SetValueBoolean($this->GetIDForIdent('Set_ManualAutoOff'), (bool)$this->ReadPropertyBoolean('ManualAutoOff'));
        @SetValueBoolean($this->GetIDForIdent('RestoreOnNext'),     (bool)$this->ReadPropertyBoolean('RestoreOnNextProp'));

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
        // Inhibits
        foreach ($this->getInhibitVars() as $vid) {
            $this->RegisterMessage($vid, self::VM_UPDATE);
            $new[] = $vid;
        }
        foreach ($this->getGlobalInhibits() as $vid) {
            $this->RegisterMessage($vid, self::VM_UPDATE);
            $new[] = $vid;
        }
        // Lichter (für manuelles Auto-Off + Memory/Szene)
        foreach ($this->getLights() as $a) {
            $v  = (int)($a['var'] ?? 0);
            $sv = (int)($a['switchVar'] ?? 0);
            if ($v  > 0 && @IPS_VariableExists($v))  { $this->RegisterMessage($v,  self::VM_UPDATE); $new[] = $v; }
            if ($sv > 0 && @IPS_VariableExists($sv)) { $this->RegisterMessage($sv, self::VM_UPDATE); $new[] = $sv; }
        }
        $this->setRegisteredIDs($new);

        $this->dbg('ApplyChanges: registriert=' . json_encode($new));
    }

    /* ================= Formular ================= */
    public function GetConfigurationForm(): string
    {
        return json_encode([
            'elements' => [
                ['type' => 'ExpansionPanel', 'caption' => $this->Translate('Bewegungsmelder'), 'items' => [[
                    'type' => 'List', 'name' => 'MotionVars', 'caption' => $this->Translate('Melder'),
                    'columns' => [[
                        'caption' => $this->Translate('Variable'), 'name' => 'var', 'width' => '320px',
                        'add' => 0, 'edit' => ['type' => 'SelectVariable']
                    ]],
                    'add' => true, 'delete' => true
                ]]],
                ['type' => 'ExpansionPanel', 'caption' => $this->Translate('Lichter'), 'items' => [[
                    'type' => 'List', 'name' => 'Lights', 'caption' => $this->Translate('Akteure'),
                    'columns' => [
                        ['caption' => $this->Translate('Typ'), 'name' => 'type', 'width' => '120px', 'add' => 'dimmer',
                         'edit' => ['type' => 'Select', 'options' => [
                             ['caption' => $this->Translate('Dimmer'),  'value' => 'dimmer'],
                             ['caption' => $this->Translate('Schalter'),'value' => 'switch']
                         ]]],
                        ['caption' => $this->Translate('Helligkeitsvariable'), 'name' => 'var', 'width' => '260px', 'add' => 0,
                         'edit' => ['type' => 'SelectVariable']],
                        ['caption' => $this->Translate('Ein/Aus/Status-Variable (optional)'), 'name' => 'switchVar', 'width' => '260px', 'add' => 0,
                         'edit' => ['type' => 'SelectVariable']],
                        ['caption' => $this->Translate('Range'), 'name' => 'range', 'width' => '160px', 'add' => 'auto',
                         'edit' => ['type' => 'Select', 'options' => [
                             ['caption' => $this->Translate('auto (aus Profil)'), 'value' => 'auto'],
                             ['caption' => $this->Translate('0..100'),            'value' => '0..100'],
                             ['caption' => $this->Translate('0..255'),            'value' => '0..255']
                         ]]]
                    ],
                    'add' => true, 'delete' => true
                ]]],
                ['type' => 'ExpansionPanel', 'caption' => $this->Translate('Stati (Inhibits)'), 'items' => [
                    ['type' => 'List', 'name' => 'InhibitVars', 'caption' => $this->Translate('Raum-Stati'),
                     'columns' => [[
                         'caption' => $this->Translate('Variable'), 'name' => 'var', 'width' => '320px',
                         'add' => 0, 'edit' => ['type' => 'SelectVariable']
                     ]], 'add' => true, 'delete' => true],
                    ['type' => 'List', 'name' => 'GlobalInhibits', 'caption' => $this->Translate('Globale Stati'),
                     'columns' => [[
                         'caption' => $this->Translate('Variable'), 'name' => 'var', 'width' => '320px',
                         'add' => 0, 'edit' => ['type' => 'SelectVariable']
                     ]], 'add' => true, 'delete' => true]
                ]],
                ['type' => 'ExpansionPanel', 'caption' => $this->Translate('Lux (optional)'), 'items' => [
                    ['type' => 'SelectVariable', 'name' => 'LuxVar', 'caption' => $this->Translate('Lux-Variable')],
                    ['type' => 'NumberSpinner',  'name' => 'LuxMax', 'caption' => $this->Translate('Lux-Maximalwert'), 'minimum' => 0, 'maximum' => 100000],
                    ['type' => 'Label', 'caption' => $this->Translate('Lux max, Timeout, Default Dim & Auto-Off sind zusätzlich als Instanzvariablen steuerbar.')]
                ]],
                // Einstellungen direkt im Modul-Dialog (Properties)
                ['type' => 'ExpansionPanel', 'caption' => $this->Translate('Einstellungen'), 'items' => [
                    ['type' => 'NumberSpinner', 'name' => 'TimeoutSec',      'caption' => $this->Translate('Timeout (Sekunden)'), 'minimum' => 5, 'maximum' => 3600],
                    ['type' => 'NumberSpinner', 'name' => 'DefaultDim',      'caption' => $this->Translate('Default Dim (%)'),    'minimum' => 1, 'maximum' => 100],
                    ['type' => 'CheckBox',      'name' => 'ManualAutoOff',   'caption' => $this->Translate('Manuelles Auto-Off aktiv')],
                    ['type' => 'CheckBox',      'name' => 'RestoreOnNextProp','caption' => $this->Translate('Szene bei nächster Bewegung wiederherstellen (Startzustand)')]
                ]]
            ],
            'actions' => [
                ['type' => 'Label', 'caption' => $this->Translate('--- Debug ---')],
                ['type' => 'Button', 'caption' => $this->Translate('Szene jetzt sichern (Live → Restore)'), 'onClick' => 'RML_DebugStoreScene($id);'],
                ['type' => 'Button', 'caption' => $this->Translate('Szene wiederherstellen'),                'onClick' => 'RML_DebugRestoreScene($id);'],
                ['type' => 'Button', 'caption' => $this->Translate('Szene-Backup löschen'),                  'onClick' => 'RML_DebugClearScene($id);'],
                ['type' => 'Button', 'caption' => $this->Translate('Test: Auto-Off jetzt'),                  'onClick' => 'RML_AutoOff($id);']
            ],
            'status' => []
        ]);
    }

    /* ================= Timer: Auto-Off ================= */
    public function AutoOff(): void
    {
        $this->dbg('AutoOff: ausgelöst – Szene sichern=' . ($this->GetValue('RestoreOnNext') ? 'ja' : 'nein'));

        // Vor dem Ausschalten die aktuelle Szene sichern (optional)
        if ($this->GetValue('RestoreOnNext')) {
            $scene = $this->captureCurrentScene();
            $this->writeAttr('SceneRestore', $scene);
            $this->dbg('AutoOff: SceneRestore gespeichert: ' . json_encode($scene));
        }
        $this->writeAttr('SceneLive', []); // leeren

        $this->setGuard(true);
        foreach ($this->getLights() as $a) {
            $type = $a['type'] ?? '';
            if ($type === 'switch') {
                $this->setSwitch((int)$a['var'], false);
            } elseif ($type === 'dimmer') {
                $this->setDimmerPct($a, 0);
            }
        }
        $this->setGuard(false);

        $this->SetTimerInterval('AutoOff', 0);
        // Debug-Anzeige & Marker zurücksetzen
        $this->WriteAttributeInteger('AutoOffUntil', 0);
        $this->SetTimerInterval('CountdownTick', 0);
        @SetValueInteger($this->GetIDForIdent('CountdownSec'), 0);

        $this->dbg('AutoOff: alle Lichter aus, Timer & Countdown gestoppt');
    }

    /* ================= MessageSink ================= */
    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message !== self::VM_UPDATE) return;

        // interne Setzungen ignorieren
        if ($this->getGuard()) {
            $this->dbg('MessageSink: GUARD aktiv – Sender=' . $SenderID . ' ignoriert');
            return;
        }

        // 1) Bewegung?
        if (in_array($SenderID, $this->getMotionVars(), true)) {
            $mv = (int)@GetValueBoolean($SenderID);
            $this->dbg('MessageSink:MOTION Sender=' . $SenderID . ' value=' . ($mv ? 'TRUE' : 'FALSE'));

            if (!$mv) return; // nur auf TRUE

            // Stati IMMER beachten
            foreach ($this->getInhibitVars() as $vid) {
                if (@GetValueBoolean($vid)) { $this->dbg('MessageSink:MOTION abgebrochen: Raum-Inhibit=' . $vid); return; }
            }
            foreach ($this->getGlobalInhibits() as $vid) {
                if (@GetValueBoolean($vid)) { $this->dbg('MessageSink:MOTION abgebrochen: Global-Inhibit=' . $vid); return; }
            }

            // Override blockiert nur Auto-ON
            if ($this->GetValue('Override')) { $this->dbg('MessageSink:MOTION abgebrochen: Override aktiv'); return; }

            // Lux-Grenze (optional)
            $luxVar = $this->ReadPropertyInteger('LuxVar');
            if ($luxVar > 0 && @IPS_VariableExists($luxVar)) {
                $lux = @GetValue($luxVar);
                $this->dbg('MessageSink:MOTION Lux=' . (is_numeric($lux) ? (string)$lux : 'n/a') . ' Max=' . $this->getSettingLuxMax());
                if (is_numeric($lux) && $lux > $this->getSettingLuxMax()) { $this->dbg('MessageSink:MOTION abgebrochen: Lux > Max'); return; }
            }

            // Wiederherstellen aus Szene?
            $rest = $this->readAttr('SceneRestore', []);
            if ($this->GetValue('RestoreOnNext') && is_array($rest) && count($rest) > 0) {
                $this->dbg('MessageSink:MOTION -> Restore-Szene');
                $this->setGuard(true);
                foreach ($rest as $st) {
                    $actor = $this->findActorByVar((int)($st['var'] ?? 0));
                    if (!$actor) continue;
                    if (($st['type'] ?? '') === 'switch') {
                        $this->setSwitch((int)$actor['var'], (bool)($st['on'] ?? false));
                    } else { // dimmer
                        $on = (bool)($st['on'] ?? false);
                        $pct = (int)($st['pct'] ?? 0);
                        $this->setDimmerPct($actor, $on ? $pct : 0);
                    }
                }
                $this->setGuard(false);

                // Szene verbrauchen
                $this->writeAttr('SceneRestore', []);
                $this->dbg('MessageSink:MOTION -> Restore-Szene angewendet & gelöscht');

                // Auto-Off Timer setzen
                $this->armAutoOffTimer('motion-restore');
                return;
            }

            // Kein Restore: Memory / Default
            $targetDefault = $this->getSettingDefaultDim();
            $this->dbg('MessageSink:MOTION -> Memory/Default, Default=' . $targetDefault);

            $this->setGuard(true);
            foreach ($this->getLights() as $a) {
                $type = $a['type'] ?? '';
                if ($type === 'switch') {
                    $mem = $this->getMemoryFor($a);
                    $on = ($mem['on'] ?? true);
                    $this->setSwitch((int)$a['var'], $on);
                    $this->dbg('  switch var=' . (int)$a['var'] . ' on=' . ($on ? '1' : '0'));
                } elseif ($type === 'dimmer') {
                    $mem = $this->getMemoryFor($a);
                    $pct = isset($mem['pct']) && $mem['pct'] > 0 ? (int)$mem['pct'] : $targetDefault;
                    $this->setDimmerPct($a, $pct);
                    $this->dbg('  dimmer var=' . (int)$a['var'] . ' pct=' . $pct);
                }
            }
            $this->setGuard(false);

            $this->writeAttr('SceneLive', $this->captureCurrentScene());
            $this->armAutoOffTimer('motion-default');
            return;
        }

        // 2) Manuelle Lichtänderungen → Memory + SceneLive + optional Auto-Off
        foreach ($this->getLights() as $a) {
            $v  = (int)($a['var'] ?? 0);
            $sv = (int)($a['switchVar'] ?? 0);

            // Dimmer-/Switch-Änderung
            if ($SenderID === $v) {
                $type = $a['type'] ?? '';
                if ($type === 'switch') {
                    $on = (bool)@GetValueBoolean($v);
                    $this->dbg('MessageSink:MANUAL switch var=' . $v . ' on=' . ($on ? '1' : '0'));
                    $this->updateMemorySwitch($a, $on);
                    $this->updateSceneLive();
                    if ($on && $this->getSettingManualAutoOff()) {
                        $this->armAutoOffTimer('manual-switch-on');
                    }
                } elseif ($type === 'dimmer') {
                    $pct = $this->getDimmerPct($a);
                    $on  = $pct > 0;
                    $this->dbg('MessageSink:MANUAL dimmer var=' . $v . ' pct=' . $pct . ' on=' . ($on ? '1' : '0'));
                    $this->updateMemoryDimmer($a, $pct, $on);
                    $this->updateSceneLive();
                    if ($on && $this->getSettingManualAutoOff()) {
                        $this->armAutoOffTimer('manual-dimmer-on');
                    }
                }
            }
            // separate Ein/Aus-Variable
            if ($sv > 0 && $SenderID === $sv) {
                $on = (bool)@GetValueBoolean($sv);
                $this->dbg('MessageSink:MANUAL switchVar=' . $sv . ' on=' . ($on ? '1' : '0'));
                $this->updateMemorySwitch($a, $on);
                $this->updateSceneLive();
                if ($on && $this->getSettingManualAutoOff()) {
                    $this->armAutoOffTimer('manual-switchVar-on');
                }
            }
        }
    }

    /* ================= Actions (View/WebFront) ================= */
    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Override':
                SetValueBoolean($this->GetIDForIdent('Override'), (bool)$Value);
                $this->dbg('RequestAction: Override=' . ((bool)$Value ? 'TRUE' : 'FALSE'));
                if ($Value) {
                    // laufenden Auto-Off abbrechen
                    $this->SetTimerInterval('AutoOff', 0);
                    $this->SetTimerInterval('CountdownTick', 0);
                    $this->WriteAttributeInteger('AutoOffUntil', 0);
                    @SetValueInteger($this->GetIDForIdent('CountdownSec'), 0);
                    $this->dbg('RequestAction: Override aktiv – AutoOff & Countdown gestoppt');
                }
                break;

            case 'Set_TimeoutSec':
                $val = max(5, min(3600, (int)$Value));
                SetValueInteger($this->GetIDForIdent('Set_TimeoutSec'), $val);
                $this->dbg('RequestAction: Set_TimeoutSec=' . $val);
                if ($this->GetTimerInterval('AutoOff') > 0) {
                    $this->armAutoOffTimer('set-timeout');
                }
                break;

            case 'Set_DefaultDim':
                $val = max(1, min(100, (int)$Value));
                SetValueInteger($this->GetIDForIdent('Set_DefaultDim'), $val);
                $this->dbg('RequestAction: Set_DefaultDim=' . $val);
                break;

            case 'Set_LuxMax':
                $val = max(0, (int)$Value);
                SetValueInteger($this->GetIDForIdent('Set_LuxMax'), $val);
                $this->dbg('RequestAction: Set_LuxMax=' . $val);
                break;

            case 'Set_ManualAutoOff':
                SetValueBoolean($this->GetIDForIdent('Set_ManualAutoOff'), (bool)$Value);
                $this->dbg('RequestAction: Set_ManualAutoOff=' . ((bool)$Value ? 'TRUE' : 'FALSE'));
                break;

            case 'RestoreOnNext':
                SetValueBoolean($this->GetIDForIdent('RestoreOnNext'), (bool)$Value);
                $this->dbg('RequestAction: RestoreOnNext=' . ((bool)$Value ? 'TRUE' : 'FALSE'));
                break;
        }
    }

    /* ================= Debug-Actions (Buttons im Formular) ================= */
    public function DebugStoreScene(): void
    {
        $scene = $this->captureCurrentScene();
        $this->writeAttr('SceneRestore', $scene);
        $this->dbg('DebugStoreScene: SceneRestore=' . json_encode($scene));
    }
    public function DebugClearScene(): void
    {
        $this->writeAttr('SceneRestore', []);
        $this->dbg('DebugClearScene: SceneRestore geleert');
    }
    public function DebugRestoreScene(): void
    {
        $rest = $this->readAttr('SceneRestore', []);
        if (!is_array($rest) || empty($rest)) { $this->dbg('DebugRestoreScene: keine Szene gespeichert'); return; }

        $this->dbg('DebugRestoreScene: stelle wieder her: ' . json_encode($rest));
        $this->setGuard(true);
        foreach ($rest as $st) {
            $actor = $this->findActorByVar((int)($st['var'] ?? 0));
            if (!$actor) continue;
            if (($st['type'] ?? '') === 'switch') {
                $this->setSwitch((int)$actor['var'], (bool)($st['on'] ?? false));
            } else { // dimmer
                $on = (bool)($st['on'] ?? false);
                $pct = (int)($st['pct'] ?? 0);
                $this->setDimmerPct($actor, $on ? $pct : 0);
            }
        }
        $this->setGuard(false);
    }

    /* ================= Settings lesen ================= */
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
        // Runtime-Variable hat Vorrang, sonst Property
        $l = (int)@GetValueInteger($this->GetIDForIdent('Set_LuxMax'));
        if ($l < 0) $l = (int)$this->ReadPropertyInteger('LuxMax');
        return max(0, $l);
    }
    private function getSettingManualAutoOff(): bool
    {
        $id = @$this->GetIDForIdent('Set_ManualAutoOff');
        if ($id && IPS_VariableExists($id)) return (bool)@GetValueBoolean($id);
        return (bool)$this->ReadPropertyBoolean('ManualAutoOff');
    }

    /* ================= Profiles & Timer ================= */
    private function ensureProfiles(): void
    {
        if (!IPS_VariableProfileExists('RML.TimeoutSec')) {
            IPS_CreateVariableProfile('RML.TimeoutSec', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileDigits('RML.TimeoutSec', 0);
            IPS_SetVariableProfileText('RML.TimeoutSec', '', ' s');
            IPS_SetVariableProfileValues('RML.TimeoutSec', 5, 3600, 1);
        }
        if (!IPS_VariableProfileExists('RML.LuxMax')) {
            IPS_CreateVariableProfile('RML.LuxMax', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileDigits('RML.LuxMax', 0);
            IPS_SetVariableProfileText('RML.LuxMax', '', ' lx');
            IPS_SetVariableProfileValues('RML.LuxMax', 0, 100000, 1);
        }
    }

    private function armAutoOffTimer(string $reason = 'generic'): void
    {
        $timeout = $this->getSettingTimeoutSec();
        $until   = time() + $timeout;

        $this->WriteAttributeInteger('AutoOffUntil', $until);

        // Auto-Off (ms)
        $this->SetTimerInterval('AutoOff', $timeout * 1000);

        // Sekundentick für visuelle Anzeige starten
        $this->SetTimerInterval('CountdownTick', 1000);

        // Sofort initiale Anzeige setzen
        $remain = max(0, $until - time());
        @SetValueInteger($this->GetIDForIdent('CountdownSec'), $remain);

        $this->dbg('armAutoOffTimer: reason=' . $reason . ' timeout=' . $timeout . 's until=' . date('H:i:s', $until));
    }

    public function CountdownTick(): void
    {
        $until = (int)$this->ReadAttributeInteger('AutoOffUntil');
        if ($until <= 0) {
            // Keine laufende Auto-Off-Phase
            $this->SetTimerInterval('CountdownTick', 0);
            @SetValueInteger($this->GetIDForIdent('CountdownSec'), 0);
            $this->dbg('CountdownTick: kein aktiver Countdown → Tick gestoppt');
            return;
        }

        $remain = $until - time();
        if ($remain <= 0) {
            // Countdown abgelaufen -> Anzeige auf 0 und Tick stoppen
            @SetValueInteger($this->GetIDForIdent('CountdownSec'), 0);
            $this->SetTimerInterval('CountdownTick', 0);
            $this->dbg('CountdownTick: abgelaufen → Anzeige=0, Tick gestoppt (AutoOff erledigt den Rest)');
            return;
        }

        @SetValueInteger($this->GetIDForIdent('CountdownSec'), $remain);
        // nicht zu „chatty“ loggen – nur größere Sprünge aufzeichnen
        if ($remain % 10 === 0) {
            $this->dbg('CountdownTick: remain=' . $remain . 's');
        }
    }

    /* ================= Lists & Lights ================= */
    private function getVarListFromProperty(string $propName): array
    {
        $raw = json_decode($this->ReadPropertyString($propName), true);
        $ids = [];
        if (is_array($raw)) {
            foreach ($raw as $row) {
                if (is_array($row) && isset($row['var'])) $ids[] = (int)$row['var'];   // List-Format
                else                                   $ids[] = (int)$row;            // Fallback
            }
        }
        return array_values(array_unique(array_filter($ids, fn($id) => $id > 0 && @IPS_VariableExists($id))));
    }
    private function getMotionVars(): array      { return $this->getVarListFromProperty('MotionVars'); }
    private function getInhibitVars(): array     { return $this->getVarListFromProperty('InhibitVars'); }
    private function getGlobalInhibits(): array  { return $this->getVarListFromProperty('GlobalInhibits'); }
    private function getLights(): array
    {
        $arr = json_decode($this->ReadPropertyString('Lights'), true);
        return is_array($arr) ? $arr : [];
    }
    private function findActorByVar(int $var): ?array
    {
        foreach ($this->getLights() as $a) {
            if ((int)($a['var'] ?? 0) === $var) return $a;
        }
        return null;
    }

    /* ================= Switch/Dimmer + Range ================= */
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
        if ($r === '' || $r === 'auto') return $this->detectRangeFromProfile((int)($actor['var'] ?? 0));
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

    /* ================= Memory (pro Lampe) ================= */
    private function getMemory(): array
    {
        $j = $this->ReadAttributeString('MemMap');
        $a = @json_decode($j, true);
        return is_array($a) ? $a : [];
    }
    private function setMemory(array $m): void
    {
        $this->WriteAttributeString('MemMap', json_encode($m));
    }
    private function getMemoryFor(array $actor): array
    {
        $m = $this->getMemory();
        $vid = (int)($actor['var'] ?? 0);
        return $m[(string)$vid] ?? [];
    }
    private function updateMemorySwitch(array $actor, bool $on): void
    {
        $vid = (int)($actor['var'] ?? 0);
        if ($vid <= 0) return;
        $m = $this->getMemory();
        $cur = $m[(string)$vid] ?? ['type' => ($actor['type'] ?? 'switch')];
        $cur['type'] = 'switch';
        $cur['on'] = (bool)$on;
        if (!isset($cur['pct'])) $cur['pct'] = $on ? 100 : 0;
        $m[(string)$vid] = $cur;
        $this->setMemory($m);
        $this->dbg('updateMemorySwitch: var=' . $vid . ' on=' . ($on ? '1' : '0'));
    }
    private function updateMemoryDimmer(array $actor, int $pct, bool $on): void
    {
        $vid = (int)($actor['var'] ?? 0);
        if ($vid <= 0) return;
        $m = $this->getMemory();
        $cur = $m[(string)$vid] ?? ['type' => 'dimmer'];
        $cur['type'] = 'dimmer';
        if ($pct > 0) $cur['pct'] = max(1, min(100, (int)$pct)); // nur >0 speichern
        $cur['on'] = (bool)$on;
        $m[(string)$vid] = $cur;
        $this->setMemory($m);
        $this->dbg('updateMemoryDimmer: var=' . $vid . ' pct=' . $pct . ' on=' . ($on ? '1' : '0'));
    }

    /* ================= Scene (Live/Restore) ================= */
    private function captureCurrentScene(): array
    {
        $scene = [];
        foreach ($this->getLights() as $a) {
            $type = $a['type'] ?? '';
            if ($type === 'switch') {
                $on = false;
                $sv = (int)($a['switchVar'] ?? 0);
                if ($sv > 0 && @IPS_VariableExists($sv)) $on = (bool)@GetValueBoolean($sv);
                else                                     $on = (bool)@GetValueBoolean((int)$a['var']);
                $scene[] = ['var' => (int)$a['var'], 'type' => 'switch', 'on' => $on];
            } elseif ($type === 'dimmer') {
                $pct = $this->getDimmerPct($a);
                $scene[] = ['var' => (int)$a['var'], 'type' => 'dimmer', 'on' => $pct > 0, 'pct' => $pct];
            }
        }
        return $scene;
    }
    private function updateSceneLive(): void
    {
        $scene = $this->captureCurrentScene();
        $this->writeAttr('SceneLive', $scene);
        $this->dbg('updateSceneLive: ' . json_encode($scene));
    }

    /* ================= Guards & Attr helpers ================= */
    private function setGuard(bool $b): void
    {
        $this->WriteAttributeBoolean('GuardInternal', $b);
    }
    private function getGuard(): bool
    {
        return (bool)$this->ReadAttributeBoolean('GuardInternal');
    }
    private function writeAttr(string $key, $val): void
    {
        $this->WriteAttributeString($key, json_encode($val));
    }
    private function readAttr(string $key, $fallback)
    {
        $j = $this->ReadAttributeString($key);
        $a = @json_decode($j, true);
        return is_array($a) ? $a : $fallback;
    }

    /* ================= Registered IDs tracking ================= */
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

    /* ================= Debug helper ================= */
    private function dbg(string $msg): void
    {
        // Alles im Instanz-Debug anzeigen:
        $this->SendDebug('RML', $msg, 0);
        // Optional zusätzlich ins System-Log:
        // IPS_LogMessage('RML', $msg);
    }
}