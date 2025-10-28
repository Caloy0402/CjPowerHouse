# Stock Validation System - Visual Flow Guide

## Complete Process Flow

```
┌─────────────────────────────────────────────────────────────────┐
│                    CUSTOMER PLACES ORDER                         │
│                  (e.g., 10 items of Product A)                  │
└──────────────────────┬──────────────────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────────────┐
│              ORDER IN PENDING STATUS                            │
│    (Cashier sees in Cashier-COD-Delivery.php or                │
│     Cashier-GCASH-Delivery.php)                                │
└──────────────────────┬──────────────────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────────────┐
│         CASHIER OPENS ORDER DETAILS MODAL                       │
│   • Reviews customer info                                       │
│   • Reviews order items                                         │
│   • Selects rider (if needed)                                   │
│   • Clicks "Update Order" button                                │
└──────────────────────┬──────────────────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────────────┐
│         🔍 AUTOMATIC STOCK VALIDATION 🔍                        │
│                                                                  │
│  JavaScript calls: check_stock_availability.php                 │
│  Button text: "Checking stock..."                              │
│                                                                  │
│  Backend checks:                                                │
│    SELECT oi.quantity, p.Quantity                              │
│    FROM order_items oi                                          │
│    JOIN products p ON oi.product_id = p.ProductID              │
│    WHERE oi.order_id = ?                                        │
│                                                                  │
│  For each item: Compare ordered vs. available                  │
└──────────────┬──────────────────────┬────────────────────────────┘
               │                      │
    ┌──────────┴───────┐   ┌─────────┴────────┐
    │ STOCK OK         │   │ INSUFFICIENT     │
    │ (Qty >= Ordered) │   │ (Qty < Ordered)  │
    └──────────┬───────┘   └─────────┬────────┘
               │                      │
               ▼                      ▼
┌──────────────────────┐   ┌──────────────────────────────────────┐
│  ✅ PROCEED WITH     │   │  ⚠️  SHOW WARNING MODAL              │
│     ORDER UPDATE     │   │                                       │
│                      │   │  Modal displays:                      │
│  • Call update-order │   │  ┌────────────────────────────────┐ │
│    .php              │   │  │ ⚠️  INSUFFICIENT STOCK         │ │
│  • Set status to     │   │  │                                 │ │
│    "Ready to Ship"   │   │  │ Cannot Process Order            │ │
│  • Assign rider      │   │  │                                 │ │
│  • Deduct stock      │   │  │ Items with Insufficient Stock:  │ │
│  • Success message   │   │  │ ┌──────┬─────────┬─────┬────┐  │ │
│  • Reload page       │   │  │ │Image │ Product │ Ord │Avl│  │ │
│                      │   │  │ ├──────┼─────────┼─────┼────┤  │ │
└──────────────────────┘   │  │ │ 📦   │Product A│ 10  │ 9 │  │ │
                           │  │ │      │         │Short│ 1 │  │ │
                           │  │ └──────┴─────────┴─────┴────┘  │ │
                           │  │                                 │ │
                           │  │ Recommended Action:             │ │
                           │  │ • Cancel order                  │ │
                           │  │ • Customer will be notified     │ │
                           │  │ • No payment charged            │ │
                           │  │                                 │ │
                           │  │ [Close] [Cancel Order & Notify] │ │
                           │  └────────────────────────────────┘ │
                           └──────────────┬───────────────────────┘
                                         │
                              ┌──────────┴──────────┐
                              │                     │
                   ┌──────────┴────────┐   ┌───────┴──────────┐
                   │  CASHIER CLOSES   │   │ CASHIER CANCELS  │
                   │  (Review later)   │   │     ORDER        │
                   └──────────┬────────┘   └───────┬──────────┘
                              │                     │
                              ▼                     ▼
                   ┌──────────────────┐   ┌────────────────────────┐
                   │  MODAL CLOSES    │   │ Call: cancel_order_    │
                   │  Order remains   │   │ insufficient_stock.php │
                   │  in Pending      │   │                        │
                   │                  │   │ Button: "Canceling..." │
                   └──────────────────┘   └───────┬────────────────┘
                                                  │
                                                  ▼
                                    ┌──────────────────────────────┐
                                    │ UPDATE ORDER IN DATABASE     │
                                    │                              │
                                    │ UPDATE orders SET            │
                                    │   order_status = 'Cancelled',│
                                    │   reason = [detailed msg]    │
                                    │ WHERE id = ?                 │
                                    │                              │
                                    │ Detailed message includes:   │
                                    │ • Product name(s)            │
                                    │ • Ordered quantity           │
                                    │ • Available stock            │
                                    │ • Shortage amount            │
                                    │ • What happens next          │
                                    │ • Apology & contact info     │
                                    └───────┬──────────────────────┘
                                            │
                                            ▼
                                    ┌──────────────────────────────┐
                                    │ SUCCESS NOTIFICATION         │
                                    │                              │
                                    │ "Order has been cancelled.   │
                                    │  The customer has been       │
                                    │  notified with detailed      │
                                    │  information about the       │
                                    │  stock shortage."            │
                                    │                              │
                                    │ Page reloads automatically   │
                                    └──────────────────────────────┘
```

## Customer Notification Example

When order is cancelled, customer sees in their order history:

```
┌────────────────────────────────────────────────────────────────────┐
│ Order #12345 - Transaction #64c7266a-287d-425d-a131-d8ae4c7f5711  │
│ Status: CANCELLED                                                  │
├────────────────────────────────────────────────────────────────────┤
│                                                                    │
│ ORDER CANCELLED - Insufficient Stock                               │
│                                                                    │
│ We sincerely apologize, but we had to cancel your order due to    │
│ insufficient stock for the following item(s):                     │
│                                                                    │
│ • batig nawung                                                     │
│   - You ordered: 10 pcs                                           │
│   - Available stock: 9 pcs                                        │
│   - Short by: 1 pcs                                               │
│                                                                    │
│ What happens next:                                                │
│ • Your order has been automatically cancelled                      │
│ • No payment has been charged                                      │
│ • You can place a new order with adjusted quantities              │
│                                                                    │
│ We apologize for any inconvenience. Our stock levels are updated  │
│ in real-time, but sometimes orders may exceed available inventory │
│ during high demand periods.                                        │
│                                                                    │
│ For questions or assistance, please contact us through our        │
│ support channels.                                                  │
│                                                                    │
│ Thank you for your understanding!                                  │
│ - CJ PowerHouse Team                                              │
└────────────────────────────────────────────────────────────────────┘
```

## Key Features Highlighted

### 🔒 **Prevention**
- Stops orders from being processed when stock is insufficient
- Real-time validation before any database changes

### 👁️ **Transparency**
- Shows exactly which items are out of stock
- Displays ordered quantity vs. available stock
- Calculates shortage amount

### 📢 **Communication**
- Automatic customer notification
- Professional, detailed explanation
- Clear next steps

### ✅ **Reliability**
- Prevents negative inventory
- Maintains accurate stock levels
- Complete audit trail

## Database Tables Affected

### `orders` table
```sql
UPDATE orders SET 
  order_status = 'Cancelled',
  reason = '[detailed explanation]'
WHERE id = ?
```

### `products` table (READ ONLY during check)
```sql
SELECT Quantity 
FROM products 
WHERE ProductID = ?
```

### `order_items` table (READ ONLY during check)
```sql
SELECT product_id, quantity 
FROM order_items 
WHERE order_id = ?
```

## Integration Points

### Cashier Pages
- ✅ Cashier-COD-Delivery.php
- ✅ Cashier-GCASH-Delivery.php

### Backend APIs
- ✅ check_stock_availability.php (new)
- ✅ cancel_order_insufficient_stock.php (new)
- ✅ update-order.php (existing, unchanged)

### Modal Components
- ✅ Stock Warning Modal (new)
- ✅ Order Details Modal (existing, enhanced)

## Success Metrics

When properly implemented, this system will:

1. **Reduce Failed Deliveries**
   - 0% oversold products
   - 100% accurate stock validation

2. **Improve Customer Experience**
   - Immediate notification
   - Clear, professional communication
   - No unexpected delivery failures

3. **Streamline Operations**
   - No manual notification writing
   - Consistent messaging
   - Faster order processing

4. **Maintain Data Integrity**
   - Accurate inventory levels
   - Complete order history
   - Proper audit trail

## Quick Reference

### For Cashiers
**Q: What happens if I try to process an order with insufficient stock?**
A: You'll see a warning modal showing which items are out of stock. You can then cancel the order, and the customer will be automatically notified.

**Q: Do I need to write a message to the customer?**
A: No! The system automatically generates a detailed, professional message explaining the situation.

**Q: What if stock becomes available later?**
A: The customer can place a new order. The cancelled order remains in the system for record-keeping.

### For Developers
**Q: How do I test this feature?**
A: Create a test order with quantity > available stock, then try to update it to "Ready to Ship" from the cashier interface.

**Q: Can I customize the customer notification message?**
A: Yes, edit the message in `cancel_order_insufficient_stock.php` around line 25-35.

**Q: Does this work for pickup orders?**
A: Currently implemented for delivery orders only (COD and GCASH). Can be extended to pickup orders if needed.

