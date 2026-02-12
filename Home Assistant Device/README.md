# Home Assistant Device

Stellt ein einzelnes Home Assistant Gerät in Symcon dar und mappt Entitäten auf Variablen.

## Voraussetzungen

- Parent: Home Assistant Splitter.
- DeviceConfig wird vom Configurator erzeugt oder manuell gepflegt.
- Home Assistant mqtt_statestream aktiv, `base_topic` passend zu `MQTTBaseTopic`.

## Konfiguration

- `DeviceName`, `DeviceArea`, `DeviceID`: nur lesbar (vom Configurator gesetzt).
- `DeviceConfig`: Liste der Entitäten mit Domain, Name, entity_id und Aktiv-Flag.
- Optional: `EnableExpertDebug`.

## Verhalten

- Legt Variablen je Entität an und abonniert deren MQTT Topics.
- Schreibt Werte aus `state` Topics in Variablen.
- Sendet Steuerbefehle an `*/set` Topics (oder REST via Splitter, wenn aktiviert).
- `MQTTBaseTopic` wird vom Splitter übernommen oder aus der Parent-Subscription ermittelt.
- Bei `light` werden zusätzliche Attribute als Variablen angelegt.
- `binary_sensor`: Präsentation und Icon werden anhand von `device_class` gemappt.
- `number`: Präsentation nutzt `min`, `max`, `step` (bzw. `native_*`) auch bei `mode: box`.
- `sensor`: `device_class: enum` mit `options` wird als Enumeration dargestellt.
- Suffix wird aus `unit_of_measurement`/`native_unit_of_measurement` und `device_class` abgeleitet.
- `lock`: Darstellung als Enumeration (locked/unlocked/locking/...) und optional `open` wenn unterstützt.
- `media_player`: Status-Variable ist read-only; Read-only-Attribute (z. B. `media_title`, `media_artist`) werden immer angelegt, schreibbare Attribute (z. B. `volume_level`, `is_volume_muted`, `shuffle`, `source`, `sound_mode`) nur wenn `supported_features` sie ausweist; Aktionen sind aktuell auf Play/Pause/Stop/Previous/Next begrenzt (kein Turn On/Off in der Action-Variable); wenn `supported_features` Turn On/Off meldet, wird eine zusätzliche boolesche `Power`-Variable angelegt, `device_class` wie `speaker`/`receiver` wird nicht speziell ausgewertet.
- `fan`: Status-Variable als Ein/Aus; Attribute (`percentage`, `oscillating`, `preset_mode`, `direction`) werden angelegt, sobald HA sie liefert; schreibbare Attribute werden nur angelegt, wenn `supported_features` sie ausweist.
- `humidifier`: Status-Variable als Ein/Aus; Attribute (`target_humidity`, `current_humidity`, `mode`, `action`) werden angelegt, sobald HA sie liefert; schreibbare Attribute werden nur angelegt, wenn `supported_features` sie ausweist.

## Icon Mapping

### Binary Sensor

| Quelle | Wert | Icon |
| --- | --- | --- |
| `device_class` | `battery` | `battery-exclamation` |
| `device_class` | `battery_charging` | `battery-bolt` |
| `device_class` | `cold` | `snowflake` |
| `device_class` | `connectivity` | `wifi` |
| `device_class` | `door` | `door-open` |
| `device_class` | `garage_door` | `garage-open` |
| `device_class` | `gas` | `cloud-bolt` |
| `device_class` | `heat` | `fire` |
| `device_class` | `light` | `lightbulb-on` |
| `device_class` | `lock` | `lock-open` |
| `device_class` | `moisture` | `droplet` |
| `device_class` | `motion` | `person-running` |
| `device_class` | `moving` | `person-running` |
| `device_class` | `occupancy` | `house-person-return` |
| `device_class` | `opening` | `up-right-from-square` |
| `device_class` | `plug` | `plug` |
| `device_class` | `power` | `bolt` |
| `device_class` | `presence` | `user` |
| `device_class` | `problem` | `triangle-exclamation` |
| `device_class` | `running` | `play` |
| `device_class` | `safety` | `shield-exclamation` |
| `device_class` | `smoke` | `fire-smoke` |
| `device_class` | `sound` | `volume-high` |
| `device_class` | `tamper` | `hand` |
| `device_class` | `update` | `arrows-rotate` |
| `device_class` | `vibration` | `chart-fft` |
| `device_class` | `window` | `window-frame-open` |

### Vacuum

| Quelle | Wert | Icon |
| --- | --- | --- |
| `state` | `cleaning` | `robot` |
| `state` | `docked` | `house` |
| `state` | `idle` | `robot` |
| `state` | `paused` | `pause` |
| `state` | `returning` | `arrow-rotate-left` |
| `state` | `error` | `triangle-exclamation` |

## Diagnose

- In der Konfiguration werden Statusinfos angezeigt (z. B. letzte MQTT-Message, letzter REST-Abruf, Entity-Count).

## Home Assistant mqtt_statestream

Siehe Home Assistant Doku: https://www.home-assistant.io/integrations/mqtt_statestream/
Mit den Optionen `include` und `exclude` kannst du gezielt Domains/Entitäten ein- oder ausschließen und damit beeinflussen, welche Integrationen hier ankommen.

```yaml
mqtt_statestream:
  base_topic: homeassistant
  publish_attributes: true
  publish_timestamps: true
```

## Schreibbare Light-Attribute

Wenn ein Light die Attribute meldet, werden für diese Attribute Variablen angelegt. Schreibbar sind:
`brightness`, `color_temp`, `color_temp_kelvin`, `effect`, `flash`, `hs_color`, `rgb_color`,
`rgbw_color`, `rgbww_color`, `transition`, `xy_color`.
