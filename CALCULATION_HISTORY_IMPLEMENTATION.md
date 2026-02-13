# Calculation History Storage System Implementation

## Overview
This document describes the implementation of the server-side calculation history storage system for the Bitrix module `prospektweb.calc`.

## Implementation Date
2026-02-13

## Components Implemented

### 1. HighloadBlock for History Storage

**Location:** `install/step3.php`

Created a HighloadBlock during module installation with the following parameters:

- **Entity Name:** `ProspektCalcOfferHistory`
- **Database Table:** `prospektcalc_offer_history`
- **Russian Name:** `История калькуляций`

**Fields:**
- `UF_DATETIME` — datetime (date and time of calculation)
- `UF_USER_ID` — integer (user ID)
- `UF_OFFER_ID` — integer (offer ID - iblock element)
- `UF_JSON` — string (JSON calculation results with ROWS=5 for large data)

**Key Features:**
- Checks if HighloadBlock already exists before creation
- Saves HighloadBlock ID to module option `HIGHLOAD_CALC_HISTORY_ID`
- Uses `installLog()` for installation logging
- Creates user fields via `CUserTypeEntity::Add()`

**Uninstallation:** `install/unstep2.php`
- Deletes HighloadBlock when user chooses to delete data
- Uses `HighloadBlockTable::delete()`

### 2. COMPLETED_CALCS Property

**Location:** `install/step3.php`

Created a property in the offers iblock:

- **CODE:** `COMPLETED_CALCS`
- **NAME:** `Завершённые расчёты`
- **PROPERTY_TYPE:** `S` (string)
- **USER_TYPE:** `directory` (linked to HighloadBlock)
- **USER_TYPE_SETTINGS:** `['TABLE_NAME' => 'prospektcalc_offer_history']`
- **MULTIPLE:** `Y` (allows multiple values)
- **SORT:** `600`

The property is created in the offers iblock using the ID from `$installData['sku_iblock_id']`.

### 3. History Limit Settings

**Files Modified:**
- `default_option.php` — Added default value: `'CALC_HISTORY_LIMIT' => 10`
- `options.php` — Added save logic and HTML input field
- `lang/ru/options.php` — Added translation: `'PROSPEKTWEB_CALC_HISTORY_LIMIT'`

**Configuration:**
- Default limit: 10 records per offer
- Configurable via module settings page
- Min: 1, Max: 100

### 4. CalculationHistoryHandler Class

**Location:** `lib/Calculator/CalculationHistoryHandler.php`

**Main Method:** `handle(array $payload): array`

**Features:**
- Validates user authentication
- Gets HighloadBlock ID from module options
- Loads entity class using `HighloadBlockTable::compileEntity()`
- For each offer in payload:
  - Checks existing record count
  - Deletes oldest record if limit exceeded (ORDER BY UF_DATETIME ASC)
  - Creates new record with current datetime, user ID, offer ID, and JSON data
  - Updates `COMPLETED_CALCS` property in offers iblock
- Returns structured response with status and results

**Response Format:**
```php
[
    'status' => 'ok',
    'results' => [
        ['offerId' => 123, 'historyId' => 456, 'status' => 'ok'],
        // ...
    ],
    'total' => 2,
    'saved' => 2,
]
```

**Autoloader:** Registered in `include.php`

### 5. API Endpoint (SAVE_CALCULATION_REQUEST)

**Location:** `tools/calculator_ajax.php`

Added PWRT protocol handler:

```php
case 'SAVE_CALCULATION_REQUEST':
    $handler = new \Prospektweb\Calc\Calculator\CalculationHistoryHandler();
    $result = $handler->handle($payload);
    
    $response = [
        'protocol' => 'pwrt-v1',
        'source' => 'bitrix',
        'target' => 'prospektweb.calc',
        'type' => 'SAVE_CALCULATION_RESPONSE',
        'requestId' => $requestId,
        'payload' => $result,
        'timestamp' => time(),
    ];
    
    sendJsonResponse($response);
    break;
```

**Payload Format:**
```json
{
  "offers": [
    {
      "offerId": 123,
      "json": { "...calculation structure..." }
    }
  ]
}
```

**Features:**
- Supports both single and multiple offers
- Sequential saving for progress tracking support
- PWRT protocol compliant

### 6. Analysis Tab Configuration

**Location:** `lib/Handlers/AdminHandler.php`

**Method:** `configureAnalysisTab()`

**Features:**
- Programmatically adds "Анализ" (Analysis) tab via inline JavaScript
- Moves `COMPLETED_CALCS` property to the new tab
- Works for both direct page access (`iblock_element_edit.php`) and popup/sidepanel views (`iblock_subelement_edit.php`)
- Called from `onBeforeEndBufferContent()` event handler

**Implementation:**
- Checks if editing element belongs to offers iblock
- Injects JavaScript to create tab dynamically
- Handles tab switching and content visibility

## Testing

All components have been validated:

✓ Syntax validation passed for all PHP files  
✓ HighloadBlock creation logic verified  
✓ COMPLETED_CALCS property creation verified  
✓ History limit settings integration verified  
✓ API endpoint structure validated  
✓ Handler logic and error handling verified  
✓ Analysis tab configuration verified  

## Usage Example

### Frontend Request (PWRT Protocol)

```javascript
const message = {
  protocol: 'pwrt-v1',
  source: 'prospektweb.calc',
  target: 'bitrix',
  type: 'SAVE_CALCULATION_REQUEST',
  requestId: 'unique-request-id',
  payload: {
    offers: [
      {
        offerId: 123,
        json: {
          // calculation data
        }
      }
    ]
  },
  timestamp: Date.now()
};

// Send to: /bitrix/tools/prospektweb.calc/calculator_ajax.php
```

### Backend Response

```json
{
  "protocol": "pwrt-v1",
  "source": "bitrix",
  "target": "prospektweb.calc",
  "type": "SAVE_CALCULATION_RESPONSE",
  "requestId": "unique-request-id",
  "payload": {
    "status": "ok",
    "results": [
      {
        "offerId": 123,
        "historyId": 456,
        "status": "ok"
      }
    ],
    "total": 1,
    "saved": 1
  },
  "timestamp": 1234567890
}
```

## Architecture Decisions

1. **HighloadBlock vs Standard Iblock:** HighloadBlock chosen for better performance with large volumes of history data.

2. **History Limit Enforcement:** Server-side limit prevents unlimited data growth while maintaining configurable flexibility.

3. **PWRT Protocol:** Consistent with existing module architecture for React calculator communication.

4. **Sequential Saving Support:** API accepts both batch and individual saves to support progress tracking in UI.

5. **Tab Configuration via JavaScript:** Avoids modifying Bitrix core files while providing flexible tab management.

## Future Enhancements

Potential improvements for future versions:

- History data cleanup scheduled agent
- History export functionality
- Advanced filtering and search in history
- History comparison tools
- User permissions for history access

## Notes

- All code follows existing module patterns and conventions
- Logging uses existing `installLog()` function for consistency
- Error handling implemented for all critical operations
- No breaking changes to existing functionality
