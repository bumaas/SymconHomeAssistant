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
 * Pflegt das Modul den Ident wieder (zentrales MaintainVariable in HASharedPresentationTrait), fällt er
 * aus der Liste; so kann der Knopf nie eine lebende Variable anbieten.
 *
 * Das Attribut wird in Create() vor dem Merkmal des Reload-Fensters registriert; gelesen wird mit @,
 * im Fenster liefert es false und alle Funktionen hier tun nichts.
 */
trait HALegacyVariableMigrationTrait
{
    /** @var list<string>|null Memo der Liste je Ausführung; null = noch nicht gelesen */
    private ?array $obsoleteIdentsMemo = null;

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
        if ($idents === null || in_array($ident, $idents, true)) {
            return false;
        }
        $idents[] = $ident;
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
        return in_array($ident, $this->readObsoleteIdents() ?? [], true);
    }

    /** Vom zentralen MaintainVariable gerufen: Der Ident wird wieder gepflegt (oder gelöscht). */
    private function forgetObsoleteIdent(string $ident): void
    {
        $idents = $this->readObsoleteIdents();
        if ($idents === null || !in_array($ident, $idents, true)) {
            return;
        }
        $this->writeObsoleteIdents(array_values(array_diff($idents, [$ident])));
    }

    /**
     * Veraltete Variablen, die es noch gibt (der Anwender kann sie selbst gelöscht haben).
     *
     * @return array<string, int> Ident => Objekt-ID
     */
    private function getObsoleteVariables(): array
    {
        $found = [];
        foreach ($this->readObsoleteIdents() ?? [] as $ident) {
            $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
            if (is_int($id) && $id > 0) {
                $found[$ident] = $id;
            }
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
     * gepflegter Ident ist nicht mehr in der Liste und bleibt. Ein anderer Wert (etwa true aus einem
     * Formular, das vor build 224 geöffnet wurde) löscht nichts, das Formular wird neu geladen.
     */
    private function handleObsoleteVariablesAction(string $ident, mixed $value = null): bool
    {
        if ($ident !== 'DeleteObsoleteVariables') {
            return false;
        }
        $obsolete = $this->readObsoleteIdents();
        if ($obsolete === null) {
            return true; // Reload-Fenster
        }
        $shown = is_string($value) ? json_decode($value, true) : null;
        $shown = is_array($shown) ? array_filter($shown, 'is_string') : [];
        $delete = array_values(array_intersect($obsolete, $shown));
        foreach ($delete as $obsoleteIdent) {
            if (@$this->GetIDForIdent($obsoleteIdent) !== false) {
                $this->UnregisterVariable($obsoleteIdent);
            }
        }
        // Bleiben: noch veraltet, nicht angezeigt und als Variable vorhanden (von Hand gelöschte fallen heraus).
        $remaining = array_values(array_diff(array_intersect($obsolete, array_keys($this->getObsoleteVariables())), $delete));
        if ($remaining !== $obsolete) {
            $this->writeObsoleteIdents($remaining);
        }
        $this->ReloadForm();
        return true;
    }

    /** @return list<string>|null null = Attribut nicht lesbar (Reload-Fenster) */
    private function readObsoleteIdents(): ?array
    {
        if ($this->obsoleteIdentsMemo !== null) {
            return $this->obsoleteIdentsMemo;
        }
        $raw = @$this->ReadAttributeString('ObsoleteVariables');
        if (!is_string($raw)) {
            return null;
        }
        $idents = json_decode($raw, true);
        $this->obsoleteIdentsMemo = is_array($idents) ? array_values(array_filter($idents, 'is_string')) : [];
        return $this->obsoleteIdentsMemo;
    }

    /** @param list<string> $idents */
    private function writeObsoleteIdents(array $idents): void
    {
        $this->obsoleteIdentsMemo = $idents;
        @$this->WriteAttributeString('ObsoleteVariables', json_encode($idents, JSON_THROW_ON_ERROR));
    }
}
