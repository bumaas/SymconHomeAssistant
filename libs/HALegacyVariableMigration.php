<?php

declare(strict_types=1);

/**
 * Variablen, die das Modul nicht mehr versorgt (Device, Entity, MQTT Discovery Device).
 *
 * Name, Position und die übrigen Objekteigenschaften gehören nach dem Anlegen dem Anwender
 * (Store-Review Punkt 11). Bis build 220 hängte das Modul „(veraltet)" an den Namen und benannte die
 * Variable bei Rückkehr der Entität zurück. Jetzt verliert eine veraltete Variable nur ihre Aktion;
 * ihr Ident steht im Attribut ObsoleteVariables. Das Log nennt sie einmal, das Formular zeigt sie mit
 * dem Knopf „Veraltete Variablen löschen“, der nach Rückfrage löscht (Muster Roborock 2.6 build 112).
 *
 * Wieder lebendig ist eine Variable auf zwei Wegen, und der Knopf bietet sie dann nicht mehr an:
 * - Der volle Pfad pflegt den Ident wieder (zentrales MaintainVariable in HASharedPresentationTrait),
 *   er fällt aus der Liste.
 * - Sie hat nach der Kennzeichnung einen Wert bekommen. Das prüft getObsoleteVariables() am Zeitpunkt der
 *   Kennzeichnung, den die Liste je Ident führt (Ident => Unix-Zeit). Der heiße Pfad (ReceiveData) liest
 *   die Liste seit build 233 nicht mehr, vorher kostete das je MQTT-Meldung einen Kernel-Aufruf
 *   (Code-Review 02.11.2026, Befund 7). Bis build 232 war die Liste eine Liste der Idents; die liest
 *   readObsoleteIdents() weiter, als Zeitpunkt gilt dann der des Lesens.
 *
 * Das Attribut wird in Create() vor dem Merkmal des Reload-Fensters registriert; gelesen wird mit @,
 * im Fenster liefert es false und alle Funktionen hier tun nichts.
 */
trait HALegacyVariableMigrationTrait
{
    /** @var array<string, int>|null Memo je Ausführung: Ident => Zeitpunkt der Kennzeichnung; null = noch nicht gelesen */
    private ?array $obsoleteIdentsMemo = null;

    /** true im heißen Pfad: Die Liste wird weder gelesen noch geschrieben, siehe Kopf der Datei. */
    private bool $obsoleteBookkeepingPaused = false;

    /** Zu Beginn von ReceiveData: Der heiße Pfad führt die Liste nicht. */
    protected function pauseObsoleteBookkeeping(): void
    {
        $this->obsoleteBookkeepingPaused = true;
    }

    /** Zu Beginn von ApplyChanges: voller Pfad, auch wenn er aus dem heißen Pfad heraus läuft. */
    protected function resumeObsoleteBookkeeping(): void
    {
        $this->obsoleteBookkeepingPaused = false;
    }

    protected function isObsoleteBookkeepingPaused(): bool
    {
        return $this->obsoleteBookkeepingPaused;
    }

    private function markVariableAsLegacy(int $variableId): bool
    {
        $object = IPS_GetObject($variableId);
        if ((int)($object['ObjectType'] ?? -1) !== OBJECTTYPE_VARIABLE) {
            return false;
        }

        $ident = trim((string)($object['ObjectIdent'] ?? ''));
        if ($ident === '') {
            return false;
        }

        $this->DisableAction($ident);

        $idents = $this->readObsoleteIdents();
        if ($idents === null || isset($idents[$ident])) {
            return false;
        }
        $idents[$ident] = time();
        $this->writeObsoleteIdents($idents);
        $this->LogMessage(
            sprintf(
                $this->Translate('The variable "%s" (ident %s) is no longer updated by the module. It can be deleted in the configuration form.'),
                (string)($object['ObjectName'] ?? ''),
                $ident
            ),
            KL_MESSAGE
        );
        return true;
    }

    private function isObsoleteIdent(string $ident): bool
    {
        return isset(($this->readObsoleteIdents() ?? [])[$ident]);
    }

    /** Vom zentralen MaintainVariable gerufen: Der Ident wird wieder gepflegt (oder gelöscht). */
    private function forgetObsoleteIdent(string $ident): void
    {
        if ($this->obsoleteBookkeepingPaused) {
            return;
        }
        $idents = $this->readObsoleteIdents();
        if ($idents === null || !isset($idents[$ident])) {
            return;
        }
        unset($idents[$ident]);
        $this->writeObsoleteIdents($idents);
    }

    /**
     * Veraltete Variablen, die es noch gibt (der Anwender kann sie selbst gelöscht haben) und die seit der
     * Kennzeichnung keinen Wert bekommen haben. Wieder beschriebene nimmt sie aus der Liste.
     *
     * @return array<string, int> Ident => Objekt-ID
     */
    private function getObsoleteVariables(): array
    {
        $idents = $this->readObsoleteIdents() ?? [];
        $found = [];
        $alive = [];
        foreach ($idents as $ident => $since) {
            $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
            if (!is_int($id) || $id <= 0) {
                continue;
            }
            if (IPS_VariableExists($id) && (int)IPS_GetVariable($id)['VariableUpdated'] > $since) {
                $alive[] = $ident;
                continue;
            }
            $found[$ident] = $id;
        }
        if ($alive !== []) {
            $this->writeObsoleteIdents(array_diff_key($idents, array_flip($alive)));
        }
        return $found;
    }

    /**
     * Hinweis und Löschknopf für den Aktionsbereich des Formulars. Der Knopf trägt die angezeigten Idents
     * als Wert: Gelöscht wird nur, was der Anwender gesehen und bestätigt hat, auch wenn ein ApplyChanges
     * im Hintergrund inzwischen weitere Variablen gekennzeichnet hat (Code-Review 02.11.2026).
     *
     * @return list<array>
     */
    private function buildObsoleteVariablesFormElements(): array
    {
        $variables = $this->getObsoleteVariables();
        $names = array_map(static fn(int $id): string => '"' . IPS_GetName($id) . '"', $variables);
        // Idents bestehen aus Buchstaben, Ziffern und _, im JSON also kein ' und kein \.
        $shown = json_encode(array_keys($variables), JSON_THROW_ON_ERROR);
        return [
            [
                'type'    => 'Label',
                'name'    => 'ObsoleteVariablesHint',
                'caption' => $names === [] ? '' : sprintf($this->Translate('No longer updated by the module and can be deleted: %s'), implode(', ', $names)),
                'visible' => $names !== []
            ],
            [
                'type'    => 'Button',
                'name'    => 'DeleteObsoleteVariables',
                'caption' => $this->Translate('Delete obsolete variables'),
                'confirm' => $this->Translate('The listed variables and their logged values will be deleted. Continue?'),
                'onClick' => 'IPS_RequestAction($id, "DeleteObsoleteVariables", \'' . $shown . '\');',
                'visible' => $names !== []
            ]
        ];
    }

    /**
     * RequestAction-Zweig; true = behandelt. $value ist die JSON-Liste der Idents, die das Formular
     * angezeigt hat. Gelöscht wird nur, was davon jetzt noch veraltet ist; ein inzwischen wieder
     * gepflegter oder beschriebener Ident ist nicht mehr dabei und bleibt. Ein anderer Wert (etwa true aus
     * einem Formular, das vor build 224 geöffnet wurde) löscht nichts, das Formular wird neu geladen.
     */
    private function handleObsoleteVariablesAction(string $ident, mixed $value = null): bool
    {
        if ($ident !== 'DeleteObsoleteVariables') {
            return false;
        }
        if ($this->readObsoleteIdents() === null) {
            return true; // Reload-Fenster
        }
        $shown = is_string($value) ? json_decode($value, true) : null;
        $shown = is_array($shown) ? array_filter($shown, 'is_string') : [];
        $current = $this->getObsoleteVariables();
        $delete = array_values(array_intersect(array_keys($current), $shown));
        foreach ($delete as $obsoleteIdent) {
            if (@$this->GetIDForIdent($obsoleteIdent) !== false) {
                $this->UnregisterVariable($obsoleteIdent);
            }
        }
        // Bleiben: noch veraltet, nicht angezeigt und als Variable vorhanden (von Hand gelöschte fallen heraus).
        $idents = $this->readObsoleteIdents() ?? [];
        $remaining = array_intersect_key($idents, array_diff_key($current, array_flip($delete)));
        if ($remaining !== $idents) {
            $this->writeObsoleteIdents($remaining);
        }
        $this->ReloadForm();
        return true;
    }

    /** @return array<string, int>|null Ident => Zeitpunkt der Kennzeichnung; null = Attribut nicht lesbar (Reload-Fenster) */
    private function readObsoleteIdents(): ?array
    {
        if ($this->obsoleteIdentsMemo !== null) {
            return $this->obsoleteIdentsMemo;
        }
        $raw = @$this->ReadAttributeString('ObsoleteVariables');
        if (!is_string($raw)) {
            return null;
        }
        $data = json_decode($raw, true);
        $idents = [];
        if (is_array($data)) {
            foreach ($data as $key => $entry) {
                if (is_string($key) && is_int($entry)) {
                    $idents[$key] = $entry;
                } elseif (is_int($key) && is_string($entry)) {
                    $idents[$entry] = time(); // Liste bis build 232: Zeitpunkt unbekannt
                }
            }
        }
        $this->obsoleteIdentsMemo = $idents;
        return $idents;
    }

    /** @param array<string, int> $idents */
    private function writeObsoleteIdents(array $idents): void
    {
        $this->obsoleteIdentsMemo = $idents;
        @$this->WriteAttributeString('ObsoleteVariables', json_encode((object)$idents, JSON_THROW_ON_ERROR));
    }
}
