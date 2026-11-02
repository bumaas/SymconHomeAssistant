[![Version](https://img.shields.io/badge/Symcon%20Version-9.0%20%3E-green.svg)](https://www.symcon.de/en/)
[![Checks](https://github.com/bumaas/SymconHomeAssistant/actions/workflows/check.yml/badge.svg)](https://github.com/bumaas/SymconHomeAssistant/actions/workflows/check.yml)
# Home Assistant

[Deutsche Fassung](README.md)

This library brings devices, entities and services from the Home Assistant world into Symcon: read values, switch, with matching variables and tiles.

There are two separate ways to use it, and both can run side by side:

1. **Classic bridge:** take over devices and entities from an existing Home Assistant installation.
2. **MQTT Discovery:** connect devices that announce themselves via Home Assistant MQTT Discovery (for example through Zigbee2MQTT or ebusd) directly, without any Home Assistant server.

This page is the English overview. The detailed documentation of each module is in German (linked below); the configuration forms of all modules are available in English.

## Contents

1. [Which way do I need?](#1-which-way-do-i-need)
2. [Modules](#2-modules)
3. [Requirements](#3-requirements)
4. [Setup](#4-setup)
5. [Supported domains and components](#5-supported-domains-and-components)
6. [Working with the instances](#6-working-with-the-instances)
7. [Troubleshooting](#7-troubleshooting)
8. [FAQ](#8-faq)

## 1. Which way do I need?

| Topic                        | Classic bridge                                | MQTT Discovery                                |
|------------------------------|-----------------------------------------------|-----------------------------------------------|
| Starting point               | existing Home Assistant installation          | device or service with MQTT Discovery         |
| Device information comes from| Home Assistant (REST API)                     | the MQTT Discovery announcements              |
| Current values come from     | Home Assistant `mqtt_statestream`             | MQTT, directly from the device                |
| Commands go via              | Home Assistant REST API                       | MQTT                                          |
| Home Assistant required      | yes                                           | no                                            |
| Main modules                 | Splitter, Configurator, Device, Entity        | MQTT Discovery Splitter, Configurator, Device |

Use the **classic bridge** when your devices already live in Home Assistant (anything an integration there connects). Use **MQTT Discovery** when a device or bridge publishes Home Assistant MQTT Discovery messages to your broker and you want it in Symcon without going through Home Assistant.

## 2. Modules

Classic bridge:

- [Home Assistant Discovery](Home%20Assistant%20Discovery/README.md): finds Home Assistant installations on the network (mDNS) and creates a configurator for them.
- [Home Assistant Configurator](Home%20Assistant%20Configurator/README.md): lists the devices and entities of Home Assistant and creates instances for them.
- [Home Assistant Splitter](Home%20Assistant%20Splitter/README.md): connects Home Assistant (MQTT and REST) with the device and entity instances.
- [Home Assistant Device](Home%20Assistant%20Device/README.md): one Home Assistant device with all its entities.
- [Home Assistant Entity](Home%20Assistant%20Entity/README.md): one single Home Assistant entity.

MQTT Discovery:

- [Home Assistant MQTT Discovery Splitter](Home%20Assistant%20MQTT%20Discovery%20Splitter/README.md): receives the discovery messages and the device topics and keeps them in a cache.
- [Home Assistant MQTT Discovery Configurator](Home%20Assistant%20MQTT%20Discovery%20Configurator/README.md): lists the announced devices and creates instances for them.
- [Home Assistant MQTT Discovery Device](Home%20Assistant%20MQTT%20Discovery%20Device/README.md): one announced device with its entities.

## 3. Requirements

- Symcon 9.0 or newer and an MQTT broker.

Classic bridge:

- A running Home Assistant installation with `mqtt_statestream` enabled.
- An MQTT Client instance in Symcon connected to the same broker.
- A Long-Lived Access Token from Home Assistant (Profile > Security > Long-Lived Access Tokens).
- For the Home Assistant Discovery module: the core instance DNS-SD Control, which Symcon provides itself.

MQTT Discovery:

- An MQTT Client instance in Symcon. If the Symcon MQTT Server is your broker, you still need an MQTT Client; it can point to the local server, for example `127.0.0.1:1028`.
- A device or service that publishes Home Assistant MQTT Discovery messages to the broker.
- Subscriptions in the MQTT Client for the discovery prefix (usually `homeassistant/#`) and for the device topics (for Zigbee2MQTT usually `zigbee2mqtt/#`).

## 4. Setup

### 4.1 Classic bridge

1. Set up an MQTT Client in Symcon. The Mosquitto broker of Home Assistant rejects anonymous connections, so create a user in Home Assistant (Settings > People > Users, for example `mqtt-symcon`) and enter its name and password in the MQTT Client. Without them the connection is refused right after it opens, and the instance shows an error. The Home Assistant Discovery module creates the instance chain without credentials because it does not know them; add them afterwards.
2. Create a **Home Assistant Splitter** and connect it to the MQTT Client.
3. In the splitter, set **MQTT Base Topic**, **HA URL** and **Long-Lived Access Token**.
4. Open the **Home Assistant Configurator** (or let the Home Assistant Discovery create one) and create the devices or entities you want.

Enable `mqtt_statestream` in Home Assistant; its `base_topic` must match **MQTT Base Topic**:

```yaml
mqtt_statestream:
  base_topic: homeassistant
  publish_attributes: true
  publish_timestamps: false
```

Keep `publish_timestamps: false`. With `true`, Home Assistant sends `last_changed` and `last_updated` for every value, which triples the number of messages; the splitter discards them anyway, because Symcon keeps its own timestamps.

### 4.2 MQTT Discovery

1. Set up an MQTT Client in Symcon. If the broker requires a login (for example Mosquitto in Home Assistant), enter user and password.
2. In the **Subscriptions** list of the MQTT Client, subscribe at least to `homeassistant/#` and to the topics of your devices, for example `zigbee2mqtt/#`. The subscriptions belong to the MQTT Client, not to the modules of this library.
3. Create a **Home Assistant MQTT Discovery Splitter** and connect it to the MQTT Client. Set **MQTT Discovery Prefix** to the discovery prefix, usually `homeassistant`.
4. Open the **Home Assistant MQTT Discovery Configurator** and create the devices you want.

## 5. Supported domains and components

Classic bridge: `light`, `switch`, `binary_sensor`, `sensor`, `number` and `input_number`, `input_text`, `datetime`, `input_datetime`, `select`, `climate`, `lock`, `button`, `input_button` are fully supported. `cover`, `valve`, `event`, `fan`, `humidifier`, `vacuum`, `lawn_mower`, `media_player`, `camera`, `image`, `device_tracker` and `update` are supported with their main states, attributes and actions. Details per domain are in the German README of [Home Assistant Device](Home%20Assistant%20Device/README.md).

MQTT Discovery: `sensor`, `binary_sensor`, `number`, `cover`, `climate`, `switch`, `select`, `button`, `light`, `image`, `device_tracker`, `lock` and `update`, plus simple Zigbee2MQTT `device_automation` triggers. Short forms of the discovery keys (`stat_t`, `temp_cmd_t` …) are understood, as are templates that read a field from the JSON payload.

## 6. Working with the instances

- **Variable names** are set when a variable is created. After that the name, position and visibility belong to you; the module never renames or moves a variable or media object it has created.
- **Reachable:** devices and entities of the classic bridge, and discovery devices with an availability topic, have a variable `Reachable` (ident `reachable`). In the classic bridge it turns false when all entities of the device have been `unavailable` for at least 10 minutes, so a Home Assistant restart (a few minutes) is not reported. Discovery devices follow their availability topic directly. Failure and recovery are written to the message log.
- **Obsolete variables:** variables the module no longer updates (entity removed in Home Assistant or deselected, device no longer announced) are not deleted automatically, so logged data and references stay intact. They lose their action, the message log names each one once, and the configuration form lists them. **Delete obsolete variables** removes exactly the listed ones after a confirmation, including their logged values; variables that become obsolete after the form was opened stay until it is opened again.
- **Invalid values:** a value the entity does not accept (an option outside the list, a number outside min/max) is not sent; `RequestAction()` reports an error with the allowed values.
- **Bundle mode:** for support and testing, the Home Assistant Device and the MQTT Discovery Splitter can load an exported JSON bundle instead of live data. It is switched in the form (panel **Bundle**) or by script with `IPS_SetProperty($id, 'SourceMode', 'bundle')`, `IPS_SetProperty($id, 'BundlePath', '<file>')` and `IPS_ApplyChanges($id)`.

## 7. Troubleshooting

Both splitters have a **Run self-test** button. It checks the usual causes (parent active, REST and token, base topic or discovery prefix, MQTT credentials, incoming data, subscriptions, discovery cache) and shows a checklist with hints. It is the quickest first step.

To watch the MQTT traffic, the free [MQTT Explorer](https://mqtt-explorer.com/) helps: connect it to the same broker and check whether topics like `<MQTT Base Topic>/switch/<entity>/state` or `homeassistant/.../config` arrive at all.

Classic bridge:

- **MQTT Client shows an error although IP and port are right:** the MQTT credentials are almost always missing (see 4.1).
- **Values in Symcon differ from Home Assistant:** states only come via `mqtt_statestream`. Check that it is enabled and that `base_topic` matches **MQTT Base Topic**. Use an MQTT Client as parent so that the retained values arrive on connect, and subscribe to `<base_topic>/#`, not `#`.
- **Whole domains are missing** (for example all `binary_sensor`): an `include`/`exclude` filter of `mqtt_statestream` hides them. The self-test lists the domains that actually arrive.
- **Switching does nothing:** commands go via REST, so **HA URL** and the token must be valid. The splitter's diagnostics show REST errors and timeouts.
- **"Device not found in Home Assistant"** (entity: "Entity not found"): the module asked a running Home Assistant and got no entities, so the device was deleted or renamed there. It checks again every five minutes. While Home Assistant is still starting, an empty answer does not count; the configuration is kept and the module asks again after 30 seconds.

MQTT Discovery:

- **No devices in the configurator:** check that the MQTT Client receives `homeassistant/#`.
- **Devices are created but get no values:** also subscribe to the device topics, for example `zigbee2mqtt/#`.
- **Retained announcements were not read completely:** press **Reconnect MQTT IO** in the MQTT Discovery Splitter. Note that this briefly disconnects every module that uses the same MQTT Client.

High CPU load:

If the load stays high even without device instances and only drops a few minutes after switching the source off, more messages arrive than the system can process. Enable **Enable topic statistics** in the splitter (panel **Expert**) to see which device sends how much, then reduce: `publish_timestamps: false`, `include`/`exclude` in `mqtt_statestream`, and a subscription limited to `<base_topic>/#` in the MQTT Client. Switch the statistics off again afterwards.

## 8. FAQ

**Do I need `mqtt_statestream` for MQTT Discovery?** No. In that path the values come directly via MQTT.

**Do I need Home Assistant for MQTT Discovery?** No. A device or service that publishes Home Assistant MQTT Discovery messages to your broker is enough.

**Can I use both ways at the same time?** Yes. The two module families are independent.

**Why does MQTT Discovery need an MQTT Client and not just the MQTT Server?** The splitter builds its cache from the retained discovery messages, and those are only delivered to a subscribing client when it connects. The Symcon MQTT Server can still be the broker; the MQTT Client then points to it.

## Donations

The module is free to use. Nobody should feel obliged, but if you like it, I am happy about a donation.

<a href="https://www.paypal.me/bumaas" target="_blank"><img src="https://www.paypalobjects.com/en_US/i/btn/btn_donate_LG.gif" border="0" /></a>
