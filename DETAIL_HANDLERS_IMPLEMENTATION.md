# Event Handlers for Detail Management Implementation

**Date:** 2025-12-24  
**Branch:** copilot/add-event-handlers-for-details

## Overview

This implementation adds 8 new event handlers for managing details, binding groups, and stages in the calculator system. The handlers enable full CRUD operations on details and their configurations through the PWRT protocol.

## Files Modified

### 1. lib/Services/DetailHandler.php (NEW)

A new service class that handles all detail-related operations.

**Key Methods:**

- `addDetail(array $data): array` - Creates a new detail with an initial empty configuration
- `copyDetail(array $data): array` - Recursively copies a detail with all its configs and children
- `addGroup(array $data): array` - Creates a binding group that combines multiple details
- `addStage(array $data): array` - Adds a new stage (configuration) to a detail
- `deleteStage(array $data): array` - Removes a stage from a detail
- `deleteDetail(array $data): array` - Deletes a detail and all its configurations
- `changeName(array $data): array` - Updates the name of a detail
- `getDetailWithChildren(int $detailId): ?array` - Recursively loads detail with nested children

**Helper Methods:**

- `createDetailElement()` - Creates CALC_DETAILS element
- `createConfigElement()` - Creates CALC_CONFIG element
- `linkConfigToDetail()` - Links configurations to details
- `getDetailById()` - Retrieves detail with properties
- `getConfigsByIds()` - Retrieves multiple configs
- `copyDetailRecursive()` - Recursive copying logic
- `copyConfig()` - Copies a single configuration
- `generateDetailName()` - Auto-generates detail names

### 2. lib/Calculator/ElementDataService.php

Updated `prepareRefreshPayload()` method to route new actions to DetailHandler.

**Added Actions:**

- `addNewDetail` → `DetailHandler::addDetail()`
- `copyDetail` → `DetailHandler::copyDetail()`
- `addNewGroup` → `DetailHandler::addGroup()`
- `addNewStage` → `DetailHandler::addStage()`
- `deleteStage` → `DetailHandler::deleteStage()`
- `deleteDetail` → `DetailHandler::deleteDetail()`
- `changeNameDetail` → `DetailHandler::changeName()`
- `getDetailWithChildren` → `DetailHandler::getDetailWithChildren()`

### 3. install/assets/js/integration.js

Added 8 new event handlers in the `CalcIntegration` class.

**New Handlers:**

- `handleGetDetailRequest()` - GET_DETAIL_REQUEST → GET_DETAIL_RESPONSE
- `handleAddNewDetailRequest()` - ADD_DETAIL_REQUEST → ADD_DETAIL_RESPONSE
- `handleCopyDetailRequest()` - COPY_DETAIL_REQUEST → COPY_DETAIL_RESPONSE
- `handleAddNewGroupRequest()` - ADD_NEW_GROUP_REQUEST → ADD_NEW_GROUP_RESPONSE
- `handleAddNewStageRequest()` - ADD_NEW_STAGE_REQUEST → ADD_NEW_STAGE_RESPONSE
- `handleDeleteStageRequest()` - DELETE_STAGE_REQUEST → DELETE_STAGE_RESPONSE
- `handleDeleteDetailRequest()` - DELETE_DETAIL_REQUEST → DELETE_DETAIL_RESPONSE
- `handleChangeNameDetailRequest()` - CHANGE_NAME_DETAIL_REQUEST → CHANGE_NAME_DETAIL_RESPONSE

All handlers follow the same pattern as `handleSyncVariantsRequest`:
1. Call `fetchRefreshData` with appropriate action
2. Send response via `sendPwrtMessage`
3. Handle errors gracefully

### 4. include.php

Registered `DetailHandler` class in the autoloader:
```php
'Prospektweb\\Calc\\Services\\DetailHandler' => 'lib/Services/DetailHandler.php',
```

## Event Protocols

### 1. ADD_NEW_DETAIL_REQUEST / ADD_NEW_DETAIL_RESPONSE

**Request:**
```javascript
{
  protocol: "pwrt-v1",
  type: "ADD_NEW_DETAIL_REQUEST",
  payload: {
    offerIds: [1, 2, 3],
    name: "Detail name" // or empty for auto-generated
  }
}
```

**Response:**
```javascript
{
  status: "ok",
  detail: {
    id: 100,
    name: "Detail name",
    type: "DETAIL"
  },
  config: {
    id: 200
  }
}
```

### 2. GET_DETAIL_REQUEST / GET_DETAIL_RESPONSE

**Request:**
```javascript
{
  protocol: "pwrt-v1",
  type: "GET_DETAIL_REQUEST",
  payload: {
    detailId: 100
  }
}
```

**Response:**
```javascript
{
  status: "ok",
  detail: {
    id: 100,
    name: "Detail name",
    type: "DETAIL" | "BINDING",
    configs: [{id: 200, name: "Config"}],
    detailIds: [101, 102], // for BINDING type
    children: [...] // recursive for BINDING type
  }
}
```

### 3. COPY_DETAIL_REQUEST / COPY_DETAIL_RESPONSE

**Request:**
```javascript
{
  protocol: "pwrt-v1",
  type: "COPY_DETAIL_REQUEST",
  payload: {
    detailId: 100,
    offerIds: [1, 2, 3]
  }
}
```

**Response:**
```javascript
{
  status: "ok",
  detail: {
    id: 110,
    name: "Detail name (копия)",
    type: "DETAIL"
  },
  configs: [{id: 210}],
  children: [...] // if group
}
```

### 4. ADD_NEW_GROUP_REQUEST / ADD_NEW_GROUP_RESPONSE

**Request:**
```javascript
{
  protocol: "pwrt-v1",
  type: "ADD_NEW_GROUP_REQUEST",
  payload: {
    offerIds: [1, 2, 3],
    detailIds: [100, 101],
    name: "Group name"
  }
}
```

**Response:**
```javascript
{
  status: "ok",
  group: {
    id: 300,
    name: "Group name",
    type: "BINDING",
    detailIds: [100, 101]
  },
  config: {
    id: 400
  }
}
```

### 5. ADD_NEW_STAGE_REQUEST / ADD_NEW_STAGE_RESPONSE

**Request:**
```javascript
{
  protocol: "pwrt-v1",
  type: "ADD_NEW_STAGE_REQUEST",
  payload: {
    detailId: 100
  }
}
```

**Response:**
```javascript
{
  status: "ok",
  config: {
    id: 201,
    detailId: 100
  }
}
```

### 6. DELETE_STAGE_REQUEST / DELETE_STAGE_RESPONSE

**Request:**
```javascript
{
  protocol: "pwrt-v1",
  type: "DELETE_STAGE_REQUEST",
  payload: {
    configId: 200,
    detailId: 100
  }
}
```

**Response:**
```javascript
{
  status: "ok",
  configId: 200,
  detailId: 100
}
```

### 7. DELETE_DETAIL_REQUEST / DELETE_DETAIL_RESPONSE

**Request:**
```javascript
{
  protocol: "pwrt-v1",
  type: "DELETE_DETAIL_REQUEST",
  payload: {
    detailId: 100
  }
}
```

**Response:**
```javascript
{
  status: "ok",
  detailId: 100,
  deletedConfigIds: [200, 201, 202]
}
```

### 8. CHANGE_NAME_DETAIL_REQUEST / CHANGE_NAME_DETAIL_RESPONSE

**Request:**
```javascript
{
  protocol: "pwrt-v1",
  type: "CHANGE_NAME_DETAIL_REQUEST",
  payload: {
    detailId: 100,
    newName: "New detail name"
  }
}
```

**Response:**
```javascript
{
  status: "ok",
  detailId: 100,
  newName: "New detail name"
}
```

## Data Model

### CALC_DETAILS Element

**Properties:**
- `TYPE` - "DETAIL" or "BINDING"
- `CALC_CONFIG` - Array of config IDs (for DETAIL type)
- `CALC_CONFIG_BINDINGS` - Array of config IDs (for BINDING type)
- `CALC_CONFIG_BINDINGS_FINISHING` - Array of config IDs (for BINDING type)
- `DETAILS` - Array of child detail IDs (for BINDING type)

### CALC_CONFIG Element

**Properties:**
- `CALCULATOR_SETTINGS` - Link to CALC_SETTINGS
- `OPERATION_VARIANT` - Link to CALC_OPERATIONS_VARIANTS
- `MATERIAL_VARIANT` - Link to CALC_MATERIALS_VARIANTS
- `EQUIPMENT` - Link to CALC_EQUIPMENT
- `QUANTITY_OPERATION_VARIANT` - Numeric value
- `QUANTITY_MATERIAL_VARIANT` - Numeric value
- `OTHER_OPTIONS` - JSON string

## Implementation Details

### Transaction Safety

The implementation uses atomic operations where possible:
- Detail creation rolls back if config creation fails
- Group creation rolls back if config creation fails
- Delete operations clean up all related entities

### Recursive Operations

The `copyDetail` operation supports full recursive copying:
1. Copies the detail
2. Copies all configurations
3. For BINDING type: recursively copies all child details
4. Maintains proper relationships in the copy

### Auto-naming

When creating a detail without a name:
- Auto-generates name like "Деталь #N"
- N is based on count of existing details + 1

### Error Handling

All operations return structured responses:
```javascript
{
  status: "ok" | "error",
  // ... operation-specific data
  message?: "Error description" // if status is "error"
}
```

## Testing

All syntax and structure checks pass:

✓ DetailHandler.php syntax valid
✓ ElementDataService.php syntax valid
✓ integration.js syntax valid
✓ All 8 methods implemented in DetailHandler
✓ All 8 handlers implemented in integration.js
✓ All 8 cases added to switch statement
✓ All 9 actions routed in ElementDataService (including GET)
✓ All handlers use fetchRefreshData
✓ All handlers send appropriate responses
✓ DetailHandler registered in autoloader

## Usage Example

```javascript
// Client-side (React app)

// Create new detail
const response = await sendMessage({
  type: 'ADD_NEW_DETAIL_REQUEST',
  payload: {
    offerIds: [1, 2, 3],
    name: 'My Detail'
  }
});

// Copy detail
const copyResponse = await sendMessage({
  type: 'COPY_DETAIL_REQUEST',
  payload: {
    detailId: response.detail.id,
    offerIds: [1, 2, 3]
  }
});

// Add stage
const stageResponse = await sendMessage({
  type: 'ADD_NEW_STAGE_REQUEST',
  payload: {
    detailId: response.detail.id
  }
});

// Create binding group
const groupResponse = await sendMessage({
  type: 'ADD_NEW_GROUP_REQUEST',
  payload: {
    offerIds: [1, 2, 3],
    detailIds: [response.detail.id, copyResponse.detail.id],
    name: 'My Group'
  }
});
```

## Backward Compatibility

✓ Existing code unchanged
✓ No breaking changes to existing handlers
✓ New handlers only add functionality
✓ All existing PWRT messages continue to work

## Future Enhancements

- Add validation for circular dependencies in groups
- Add support for batch operations
- Add transaction support with rollback
- Add detailed logging for audit trail
- Add permissions checking for operations
- Optimize recursive loading with caching

## References

- Problem Statement: Task requirements document
- PWRT Protocol: pwrt-v1 message protocol specification
- SyncVariantsHandler: Pattern for handler implementation
- Related Files: InitPayloadService.php, SaveHandler.php
