<?php

// Complete e-commerce workflow: search → validate → recommend → checkout
agent('product-search')->provider('openai')->system('Find products')
    ->tool('search', 'Search inventory', fn (string $q) => [['id' => 1, 'name' => 'Wireless Headphones', 'price' => 89.99, 'rating' => 4.5, 'stock' => 45]])
    ->tool('check_stock', 'Check availability', fn (int $id, int $qty) => ['available' => true, 'warehouse' => 'NYC', 'ships_in' => rand(1, 3).' days']);

agent('pricing')->provider('anthropic')->system('Apply pricing rules')
    ->tool('apply_discount', 'Calculate discounts', fn (float $price, string $code) => ['original' => $price, 'discount' => 0.20, 'final' => $price * 0.8, 'code' => $code])
    ->tool('calculate_tax', 'Calculate tax', fn (float $price, string $state) => ['price' => $price, 'tax' => round($price * 0.08, 2), 'total' => round($price * 1.08, 2)]);

agent('recommendations')->provider('openai')->system('Suggest products')
    ->tool('get_similar', 'Find similar items', fn (int $id) => [['id' => 2, 'name' => 'Premium Headphones', 'price' => 149.99, 'match' => '92%']]);

agent('checkout')->provider('anthropic')->system('Process orders')
    ->tool('create_order', 'Create order', fn (array $items, float $total) => ['order_id' => 'ORD-'.rand(1000, 9999), 'total' => $total, 'status' => 'confirmed']);

// Complete shopping workflow with tool orchestration
$order = pipeline('shopping-flow')
    ->agent('product-search')
    ->agent('pricing', fn ($products) => "Apply discount SAVE20 to: {$products}")
    ->agent('recommendations', fn ($priced) => "Suggest items based on: {$priced}")
    ->agent('checkout', fn ($cart) => "Create order for: {$cart}")
    ->run('Find wireless headphones under $100 with free shipping');

echo $order;
