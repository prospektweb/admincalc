# SYNC_VARIANTS_REQUEST Handler Implementation

**Date:** 2025-12-23  
**Branch:** copilot/add-sync-variants-handler

## Overview

This implementation adds support for the `SYNC_VARIANTS_REQUEST` PWRT protocol message, which enables React-калькулятор to synchronize detail variants and calculator configurations with Bitrix.

## Changes Made

### 1. New Service: SyncVariantsHandler

**File:** `lib/Services/SyncVariantsHandler.php`

A new service that handles synchronization of variants (детали и скрепления) with their calculator configurations.

**Key Methods:**
- `handle(array $payload)` - Main entry point, processes synchronization request
- `processItems(array $items)` - Processes array of detail/binding items
- `processItem(array $item)` - Processes single item (detail or binding)
- `createDetail()` / `updateDetail()` - Manages CALC_DETAILS elements
- `createOrUpdateConfig()` - Manages CALC_CONFIG elements
- `deleteConfigs()` - Removes old configurations
- `updateDetailBindings()` - Updates relationships between elements

**Features:**
- Creates/updates CALC_DETAILS elements
- Creates/updates CALC_CONFIG elements with all calculator settings
- Manages relationships: CALC_CONFIG, DETAILS
- Tracks statistics (created, updated, deleted)
- Handles errors gracefully
- Supports both "detail" and "binding" types

### 2. PWRT Protocol Support in calculator_ajax.php

**File:** `tools/calculator_ajax.php`

Extended the AJAX endpoint to support PWRT protocol messages alongside existing action-based requests.

**Changes:**
- Added raw input detection for PWRT messages
- Added message type routing for `SYNC_VARIANTS_REQUEST`
- Returns structured `SYNC_VARIANTS_RESPONSE` with PWRT protocol format
- Maintains backward compatibility with action-based requests
- Added proper error handling for PWRT messages

### 3. New CALC_CONFIG Properties

Added 7 new properties to CALC_CONFIG infoblock for storing calculator configuration details:

| Property Code | Name | Type | Link To | Sort |
|--------------|------|------|---------|------|
| CALCULATOR_SETTINGS | Настройки калькулятора | E | CALC_SETTINGS | 100 |
| OPERATION_VARIANT | Вариант операции | E | CALC_OPERATIONS_VARIANTS | 200 |
| MATERIAL_VARIANT | Вариант материала | E | CALC_MATERIALS_VARIANTS | 300 |
| EQUIPMENT | Оборудование | E | CALC_EQUIPMENT | 400 |
| QUANTITY_OPERATION_VARIANT | Количество операций | N | - | 500 |
| QUANTITY_MATERIAL_VARIANT | Количество материала | N | - | 600 |
| OTHER_OPTIONS | Прочие опции (JSON) | HTML | - | 700 |

**Modified Files:**
- `lib/Install/IblockCreator.php` - Added properties to `createCalcConfigIblock()`
- `install/step3.php` - Added properties to `$settingsProps` and property binding updates

### 4. Class Registration

**File:** `include.php`

Registered the new `SyncVariantsHandler` class in the autoloader:
```php
'Prospektweb\\Calc\\Services\\SyncVariantsHandler' => 'lib/Services/SyncVariantsHandler.php',
```

## Message Protocol

### Request: SYNC_VARIANTS_REQUEST

```json
{
  "protocol": "pwrt-v1",
  "source": "prospektweb.calc",
  "target": "bitrix",
  "type": "SYNC_VARIANTS_REQUEST",
  "requestId": "uuid",
  "timestamp": 1234567890,
  "payload": {
    "items": [
      {
        "id": "detail-1",
        "name": "Detail Name",
        "type": "detail",
        "bitrixId": null,
        "calculators": [
          {
            "id": "calc-1",
            "configId": null,
            "calculatorCode": "calc-code",
            "operationVariantId": 10,
            "materialVariantId": 20,
            "equipmentId": 30,
            "operationQuantity": 100,
            "materialQuantity": 50,
            "otherOptions": {"key": "value"}
          }
        ]
      }
    ],
    "offerIds": [1, 2, 3],
    "deletedConfigIds": [101, 102],
    "context": {}
  }
}
```

### Response: SYNC_VARIANTS_RESPONSE

```json
{
  "protocol": "pwrt-v1",
  "source": "bitrix",
  "target": "prospektweb.calc",
  "type": "SYNC_VARIANTS_RESPONSE",
  "requestId": "uuid",
  "timestamp": 1234567890,
  "payload": {
    "status": "ok",
    "items": [
      {
        "id": "detail-1",
        "bitrixId": 100,
        "type": "detail",
        "calculators": [
          {
            "id": "calc-1",
            "configId": 200
          }
        ]
      }
    ],
    "canCalculate": true,
    "stats": {
      "detailsCreated": 1,
      "detailsUpdated": 0,
      "configsCreated": 1,
      "configsDeleted": 2
    },
    "errors": []
  }
}
```

## Item Types

### Detail (type: "detail")
- Regular product detail/component
- Has `calculators` array
- Configurations linked via `CALC_CONFIG` property

### Binding (type: "binding")
- Binding/fastening element
- Has `bindingCalculators` array for binding operations
- Has `finishingCalculators` array for finishing operations
- Has `childIds` array for linked detail IDs
- Child details linked via `DETAILS` property

## Data Flow

1. React app sends `SYNC_VARIANTS_REQUEST` with items to synchronize
2. `calculator_ajax.php` detects PWRT protocol and routes to `SyncVariantsHandler`
3. Handler processes each item:
   - Creates/updates CALC_DETAILS element
   - Creates/updates CALC_CONFIG elements for calculators
   - Links configurations to detail
   - Deletes old configurations if specified
4. Returns `SYNC_VARIANTS_RESPONSE` with:
   - Bitrix IDs for created/updated items
   - Config IDs for calculators
   - Statistics about operations performed
   - Any errors encountered

## Property Mapping

Request payload → Bitrix properties:

| Payload Field | CALC_CONFIG Property | Notes |
|--------------|---------------------|-------|
| calculatorCode | CALCULATOR_SETTINGS | Link to CALC_SETTINGS element |
| operationVariantId | OPERATION_VARIANT | Link to CALC_OPERATIONS_VARIANTS |
| materialVariantId | MATERIAL_VARIANT | Link to CALC_MATERIALS_VARIANTS |
| equipmentId | EQUIPMENT | Link to CALC_EQUIPMENT |
| operationQuantity | QUANTITY_OPERATION_VARIANT | Numeric value |
| materialQuantity | QUANTITY_MATERIAL_VARIANT | Numeric value |
| otherOptions | OTHER_OPTIONS | JSON encoded string |

## Installation

When upgrading the module:

1. New properties will be automatically created in CALC_CONFIG infoblock
2. Property bindings will be automatically configured in `install/step3.php`
3. Existing data remains intact (backward compatible)

## Testing

All syntax validation and unit tests passed:
- ✅ SyncVariantsHandler.php syntax check
- ✅ calculator_ajax.php syntax check
- ✅ IblockCreator.php syntax check
- ✅ step3.php syntax check
- ✅ Empty payload handling
- ✅ Detail processing
- ✅ Binding processing
- ✅ Config deletion
- ✅ PWRT message structure validation

## Backward Compatibility

- ✅ Old action-based requests continue to work
- ✅ Existing CALC_CONFIG properties preserved
- ✅ New properties added without breaking existing functionality
- ✅ Module installation/update process unchanged

## Future Enhancements

- Implement `resolveChildIds()` method for React ID → Bitrix ID mapping
- Add more sophisticated validation in `checkCanCalculate()`
- Add support for batch operations
- Add transaction support for atomic updates

## References

- Problem Statement: Task description for SYNC_VARIANTS_REQUEST implementation
- PWRT Protocol: pwrt-v1 message protocol specification
- Related Files: InitPayloadService.php, SaveHandler.php, ElementDataService.php
