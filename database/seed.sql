-- ============================================================================
-- SokoHub Marketplace Seed Data
-- Demo Password for ALL seeded accounts: SokoHub@2026
-- ============================================================================

SET NAMES utf8mb4;

-- Password hash generated via PHP password_hash('SokoHub@2026', PASSWORD_BCRYPT, ['cost' => 10])
INSERT INTO users (id, full_name, email, phone, password_hash, role, status) VALUES
(1, 'SokoHub Administrator', 'admin@sokohub.co.ke', '+254700100001', '$2y$10$Vmadn3FXQgU.SHsicHmNpeX/HxHp59fi.3q/U24YQmjD3Eg9YTbAO', 'admin', 'active'),
(2, 'Ian Mwangi', 'seller@sokohub.co.ke', '+254711200002', '$2y$10$Vmadn3FXQgU.SHsicHmNpeX/HxHp59fi.3q/U24YQmjD3Eg9YTbAO', 'seller', 'active'),
(3, 'Grace Wanjiku', 'grace.farms@sokohub.co.ke', '+254722300003', '$2y$10$Vmadn3FXQgU.SHsicHmNpeX/HxHp59fi.3q/U24YQmjD3Eg9YTbAO', 'seller', 'active'),
(4, 'Kennedy Kalawa', 'buyer@sokohub.co.ke', '+254733400004', '$2y$10$Vmadn3FXQgU.SHsicHmNpeX/HxHp59fi.3q/U24YQmjD3Eg9YTbAO', 'buyer', 'active'),
(5, 'Faith Muthoni', 'faith.buyer@sokohub.co.ke', '+254744500005', '$2y$10$Vmadn3FXQgU.SHsicHmNpeX/HxHp59fi.3q/U24YQmjD3Eg9YTbAO', 'buyer', 'active'),
(6, 'Brian Kiprop', 'rider@sokohub.co.ke', '+254755600006', '$2y$10$Vmadn3FXQgU.SHsicHmNpeX/HxHp59fi.3q/U24YQmjD3Eg9YTbAO', 'delivery', 'active'),
(7, 'Samuel Njoroge', 'samuel.rider@sokohub.co.ke', '+254766700007', '$2y$10$Vmadn3FXQgU.SHsicHmNpeX/HxHp59fi.3q/U24YQmjD3Eg9YTbAO', 'delivery', 'active');

INSERT INTO seller_profiles (user_id, business_name, business_type, town, description) VALUES
(2, 'Ian Fresh & Home Mart', 'retail', 'Kutus', 'Quality cooking gas refills, electronics, and household essentials in Kirinyaga.'),
(3, 'Wanjiku Organic Produce & Fashion', 'food_beverage', 'Kerugoya', 'Farm-fresh Kirinyaga vegetables, organic baskets, and curated apparel.');

INSERT INTO delivery_profiles (user_id, transport_method, license_number, service_area, is_available) VALUES
(6, 'motorbike', 'DL-KE-88421', 'Kutus - Kagio - Kerugoya', 1),
(7, 'car', 'DL-KE-99210', 'Kirinyaga County & Embu', 1);

INSERT INTO categories (id, name, slug, description) VALUES
(1, 'Clothes', 'clothes', 'Everyday apparel, coats, shirts, and local tailoring.'),
(2, 'Gas', 'gas', 'LPG cooking gas cylinders, refills, regulators, and burners.'),
(3, 'Food', 'food', 'Fresh organic produce, groceries, and farm baskets.'),
(4, 'Electronics', 'electronics', 'Home appliances, kitchen electronics, and smart gadgets.'),
(5, 'Others', 'others', 'General household items, packaging, and market bundles.');

INSERT INTO products (id, seller_id, category_id, name, description, price, original_price, stock_qty, condition_type, image_path, is_featured, status) VALUES
(1, 2, 2, '6kg Cooking Gas Refill & Cylinder', 'Sealed 6kg local LPG cylinder refill with safety check and fast doorstep swap.', 1200.00, 1800.00, 24, 'New', 'assets/img/gas-tanks.jpg', 1, 'active'),
(2, 2, 4, 'Compact Home Fridge & Washer Bundle', 'Energy-saving home appliance deal inspected and tested for student hostels and family homes.', 18500.00, 24000.00, 4, 'Second Hand', 'assets/img/electronics.jpg', 1, 'active'),
(3, 3, 1, 'Classic Winter & Trench Apparel Pack', 'Warm, clean boutique coats and shirts curated for campus and office wear.', 4500.00, 7500.00, 15, 'New', 'assets/img/clothes.jpg', 1, 'active'),
(4, 3, 3, 'Organic Grocery Market Basket', 'Assorted fresh greens, peppers, milk, pasta, and pantry staples packed daily.', 1400.00, 2000.00, 32, 'New', 'assets/img/organic-food.jpg', 1, 'active'),
(5, 3, 3, 'Kirinyaga Farm Crate (Tomatoes & Peppers)', 'Harvested every morning from local Kirinyaga farms — vibrant bell peppers, tomatoes, and greens.', 950.00, 1300.00, 3, 'New', 'assets/img/fresh-produce.jpg', 0, 'active'),
(6, 2, 2, '13kg Home Gas Safety Kit + Regulator', 'Full 13kg LPG safety kit with high-pressure hose, regulator, and free Kutus delivery.', 3200.00, 3900.00, 9, 'New', 'assets/img/gas-mascot.jpg', 0, 'active'),
(7, 2, 4, 'Smart LED Television 32-Inch', 'Crisp HD LED TV with HDMI/USB ports and 6-month seller warranty.', 14500.00, 17900.00, 5, 'New', 'assets/img/electronics.jpg', 0, 'active'),
(8, 3, 1, 'Smart Casual Cotton Shirts (2-Pack)', 'Breathable cotton button-down shirts suitable for lectures and business meetings.', 1500.00, 2100.00, 20, 'New', 'assets/img/clothes.jpg', 0, 'active'),
(9, 2, 5, 'Student Hostel Starter Crate', 'Essential hostel bundle containing cleaning items, storage basket, and kitchen utensils.', 2200.00, 2800.00, 2, 'New', 'assets/img/cart-icon.jpg', 0, 'active'),
(10, 3, 5, 'Artisan Market Spice & Honey Gift Box', 'Locally sourced highland honey and organic spice jars packaged by Kirinyaga artisans.', 1650.00, 2000.00, 14, 'New', 'assets/img/indoor-market.jpg', 0, 'active');

-- Historical & Active Orders for Meaningful Reports
INSERT INTO orders (id, order_code, buyer_id, total_amount, payment_method, payment_status, order_status, delivery_address, delivery_town, notes, created_at) VALUES
(1, 'SKH-2026-1001', 4, 2600.00, 'M-Pesa', 'paid', 'delivered', 'Kirinyaga University Gate B, Hostel 4', 'Kutus', 'Call on arrival at Gate B', DATE_SUB(NOW(), INTERVAL 12 DAY)),
(2, 'SKH-2026-1002', 5, 4500.00, 'M-Pesa', 'paid', 'delivered', 'Kerugoya Town, Opposite Stage', 'Kerugoya', 'Leave with reception', DATE_SUB(NOW(), INTERVAL 9 DAY)),
(3, 'SKH-2026-1003', 4, 18500.00, 'Bank Card', 'paid', 'delivered', 'Kutus Market Road, Plot 14', 'Kutus', 'Handle with care', DATE_SUB(NOW(), INTERVAL 6 DAY)),
(4, 'SKH-2026-1004', 5, 3200.00, 'M-Pesa', 'paid', 'shipped', 'Kagio Junction, Green Apartments', 'Kagio', 'Afternoon delivery preferred', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(5, 'SKH-2026-1005', 4, 2350.00, 'Cash on Delivery', 'unpaid', 'processing', 'Kutus Kwa Mwea Road, Room 12', 'Kutus', 'Bring change for 3000', DATE_SUB(NOW(), INTERVAL 1 DAY)),
(6, 'SKH-2026-1006', 5, 14500.00, 'M-Pesa', 'paid', 'pending', 'Sagana Highway Estate, House 8', 'Sagana', 'Confirm before dispatch', NOW());

INSERT INTO order_items (order_id, product_id, seller_id, quantity, unit_price, line_total) VALUES
(1, 1, 2, 1, 1200.00, 1200.00),
(1, 4, 3, 1, 1400.00, 1400.00),
(2, 3, 3, 1, 4500.00, 4500.00),
(3, 2, 2, 1, 18500.00, 18500.00),
(4, 6, 2, 1, 3200.00, 3200.00),
(5, 4, 3, 1, 1400.00, 1400.00),
(5, 5, 3, 1, 950.00, 950.00),
(6, 7, 2, 1, 14500.00, 14500.00);

INSERT INTO deliveries (order_id, delivery_person_id, pickup_town, dropoff_address, delivery_fee, delivery_status, tracking_notes, assigned_at, delivered_at) VALUES
(1, 6, 'Kutus Market', 'Kirinyaga University Gate B, Hostel 4', 150.00, 'delivered', 'Delivered to buyer in 35 mins', DATE_SUB(NOW(), INTERVAL 12 DAY), DATE_SUB(NOW(), INTERVAL 12 DAY)),
(2, 7, 'Kerugoya Central', 'Kerugoya Town, Opposite Stage', 200.00, 'delivered', 'Signed at stage kiosk', DATE_SUB(NOW(), INTERVAL 9 DAY), DATE_SUB(NOW(), INTERVAL 9 DAY)),
(3, 7, 'Kutus Market', 'Kutus Market Road, Plot 14', 350.00, 'delivered', 'Van delivery completed safely', DATE_SUB(NOW(), INTERVAL 6 DAY), DATE_SUB(NOW(), INTERVAL 6 DAY)),
(4, 6, 'Kutus Market', 'Kagio Junction, Green Apartments', 200.00, 'in_transit', 'Rider en route via Kutus-Kagio road', DATE_SUB(NOW(), INTERVAL 2 DAY), NULL),
(5, 6, 'Kerugoya Central', 'Kutus Kwa Mwea Road, Room 12', 150.00, 'assigned', 'Rider assigned, awaiting pickup', DATE_SUB(NOW(), INTERVAL 12 HOUR), NULL),
(6, NULL, 'Kutus Market', 'Sagana Highway Estate, House 8', 250.00, 'pending', 'Awaiting rider claim', NULL, NULL);

INSERT INTO contact_messages (name, email, subject, message, status) VALUES
('Mercy Njeri', 'mercy.njeri@example.com', 'Bulk Produce Order Inquiry', 'Hello SokoHub, can we schedule weekly vegetable deliveries for a hostel cafeteria in Kutus?', 'new'),
('David Mutua', 'david.mutua@example.com', 'Seller Verification Question', 'How long does it take to start listing electronics after seller registration?', 'read'),
('Alice Wambui', 'alice.wambui@example.com', 'Great Delivery Speed', 'My 6kg gas cylinder arrived at Gate B in under 40 minutes. Keep up the great service!', 'resolved');

-- Seeded M-Pesa Payments & Transactions
INSERT INTO payments (order_id, method, amount, status, phone, mpesa_receipt, result_code, result_desc, created_at) VALUES
(1, 'mpesa', 2600.00, 'succeeded', '254733400004', 'QGH7182901', 0, 'The service request is processed successfully.', DATE_SUB(NOW(), INTERVAL 12 DAY)),
(2, 'mpesa', 4500.00, 'succeeded', '254744500005', 'QGH8391022', 0, 'The service request is processed successfully.', DATE_SUB(NOW(), INTERVAL 9 DAY)),
(3, 'bank_card', 18500.00, 'succeeded', '254733400004', 'AUTH-VISA-9910', 0, 'Approved', DATE_SUB(NOW(), INTERVAL 6 DAY)),
(4, 'mpesa', 3200.00, 'succeeded', '254744500005', 'QGH9482103', 0, 'The service request is processed successfully.', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(6, 'mpesa', 14500.00, 'pending', '254744500005', NULL, NULL, 'STK push prompt sent to customer phone.', NOW());
