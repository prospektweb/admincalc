'use strict';

function parseNumber(raw, fallback) {
  var normalized = typeof raw === 'string' ? raw.replace(/\s+/g, '').replace(',', '.') : raw;
  var num = Number(normalized);
  return Number.isFinite(num) ? num : fallback;
}

function normalizeRangeBound(value, fallback) {
  if (value === null || typeof value === 'undefined' || value === '') return fallback;
  var num = parseNumber(value, Number.NaN);
  return Number.isFinite(num) ? num : fallback;
}

function sortPriceRanges(ranges) {
  return (Array.isArray(ranges) ? ranges.slice() : []).sort(function (a, b) {
    var fromA = normalizeRangeBound(a && a.quantity_from, 0);
    var fromB = normalizeRangeBound(b && b.quantity_from, 0);
    if (fromA !== fromB) return fromA - fromB;

    var toA = normalizeRangeBound(a && a.quantity_to, Number.POSITIVE_INFINITY);
    var toB = normalizeRangeBound(b && b.quantity_to, Number.POSITIVE_INFINITY);
    if (toA !== toB) return toA - toB;

    return parseNumber(a && a.price, 0) - parseNumber(b && b.price, 0);
  });
}

function pickRangePriceForQuantity(ranges, quantity) {
  var targetQuantity = parseNumber(quantity, Number.NaN);
  if (!Number.isFinite(targetQuantity)) return null;

  var sorted = sortPriceRanges(ranges);
  for (var i = 0; i < sorted.length; i++) {
    var row = sorted[i];
    var from = normalizeRangeBound(row && row.quantity_from, 0);
    var to = normalizeRangeBound(row && row.quantity_to, Number.POSITIVE_INFINITY);
    if (targetQuantity >= from && targetQuantity <= to) {
      return row;
    }
  }

  return null;
}

function assertPrice(quantity, expectedPrice) {
  var picked = pickRangePriceForQuantity([
    { quantity_from: 1, quantity_to: 99, price: 1600 },
    { quantity_from: 100, quantity_to: 499, price: 1450 },
    { quantity_from: 500, quantity_to: null, price: 1300 }
  ], quantity);

  if (!picked || picked.price !== expectedPrice) {
    throw new Error('Expected ' + quantity + ' -> ' + expectedPrice + ', got ' + (picked && picked.price));
  }
}

assertPrice(50, 1600);
assertPrice(300, 1450);
assertPrice(1000, 1300);

if (pickRangePriceForQuantity([{ quantity_from: 1, quantity_to: 99, price: 1600 }], 300) !== null) {
  throw new Error('Out-of-range quantity must not fall back to the first row');
}

console.log('frontcalc range picker tests passed');
