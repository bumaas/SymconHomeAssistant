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

## Diagnose

- In der Konfiguration werden Statusinfos angezeigt (z. B. letzte MQTT-Message, letzter REST-Abruf, Entity-Count).

## Home Assistant mqtt_statestream

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




