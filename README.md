# Home Assistant

Dieses Repository stellt Symcon Module bereit, um Home Assistant Geräte in Symcon einzubinden und zu steuern.

## Module

- [Home Assistant Discovery](Home%20Assistant%20Discovery/README.md)
- [Home Assistant Configurator](Home%20Assistant%20Configurator/README.md)
- [Home Assistant Splitter](Home%20Assistant%20Splitter/README.md)
- [Home Assistant Device](Home%20Assistant%20Device/README.md)

## Unterstützte Domains

| Domain         | Status                | Hinweise |
| -------------- | --------------------- | -------- |
| `light`        | voll                  | Attribute + Schreibbar |
| `switch`       | voll                  | Schaltbar |
| `binary_sensor`| voll                  | device_class + Icons |
| `number`       | voll                  | Slider/Min/Max/Step, REST `set_value` |
| `sensor`       | voll                  | Units/Suffix, `enum` als Enumeration |
| `select`       | voll                  | Enumeration |
| `climate`      | teilweise             | REST `set_temperature` |
| `lock`         | teilweise             | REST `lock`/`unlock` |
| `cover`        | teilweise             | REST `open_cover`/`close_cover` |
| `event`        | teilweise             | Basisvariablen |

## Voraussetzungen

- Symcon ab Version 8.2
- MQTT Broker in HomeAssistant und eine MQTT Client Instanz in Symcon oder alternativ ein Symcon MQTT Server
- Home Assistant mit aktivierter MQTT Integration (Statestream)
- Optional: mDNS/DNS-SD (Discovery)
- Long-Lived Access Token (REST)
- MQTT Statestream in Home Assistant (`mqtt_statestream`) aktiv und `base_topic` passend zu `MQTTBaseTopic`

## Installation

- Modul über den Symcon Module Store hinzufügen (Library: "Home Assistant").
- Danach die benötigten Instanzen anlegen.

## Einrichtung (Kurzfassung)

1. MQTT Client in Symcon einrichten und mit dem Broker verbinden.
2. Home Assistant Splitter anlegen und mit dem MQTT Client verbinden.
3. Im Splitter `MQTTBaseTopic` setzen (typisch: `homeassistant`) sowie `HAUrl` und `HAToken` für REST.
4. Home Assistant Discovery ausführen, um eine Configurator Instanz zu erstellen.
5. Im Configurator gefundene Geräte auswählen und Device Instanzen erzeugen.

## Home Assistant mqtt_statestream

Damit Zustandsänderungen per MQTT ankommen, muss `mqtt_statestream` aktiv sein und das `base_topic` mit `MQTTBaseTopic` übereinstimmen:

```yaml
mqtt_statestream:
  base_topic: homeassistant
  publish_attributes: true
  publish_timestamps: true
```

## Datenfluss

Die Kommunikation zwischen Device/Configurator und Splitter erfolgt über definierte DataIDs aus `lib/HAIds.php`.

- Device/Configurator -> Splitter: `{E62B0B4F-1B5C-4F2C-9B6B-2C86F5B7C1D1}`
- Splitter -> Device/Configurator: `{F4A2B9F1-1D3B-44A9-9B6A-0D3A5A7D6E10}`
- Splitter <-> MQTT Client (RX): `{7F7632D9-FA40-4F38-8DEA-C83CD4325A32}`
- Splitter <-> MQTT Client (TX): `{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}`

## GUIDs der Module

| Modul                         | Typ          | GUID                                   |
| ---------------------------- | ------------ | -------------------------------------- |
| Home Assistant Discovery     | Discovery    | `{C36FEFA4-4732-CBD6-0216-A1DB30D036CF}` |
| Home Assistant Configurator  | Configurator | `{B9830F89-98E6-106C-CD6C-A3AD76FD5AE9}` |
| Home Assistant Splitter      | Splitter     | `{0A4C4B31-2F59-4D21-8F62-3A12A0A0F3E1}` |
| Home Assistant Device        | Device       | `{72D6A284-1870-4E11-92D8-0402C8233C29}` |
