<?php
// ticket_item_template.php

if (!isset($ticket)) { return; } // Exit if no data

$product_id = htmlspecialchars($ticket['product_id']);
$name = htmlspecialchars($ticket['name']);
$price = (float)$ticket['price'];
$image_url = htmlspecialchars($ticket['image_url'] ?? 'Images/placeholder.webp'); 
?>

<div class="ticket-card" data-id="<?php echo $product_id; ?>">
    
    <div class="ticket-img-wrapper">
        <img src="<?php echo $image_url; ?>" alt="<?php echo $name; ?>">
    </div>

    <div class="ticket-content">
        
        <div style="margin-bottom:15px;">
            <h4 style="font-size:1rem; color:#333; margin:0 0 5px 0; min-height:40px; display:flex; align-items:center; justify-content:center;">
                <?php echo $name; ?>
            </h4>
            <span style="font-size:1.2rem; font-weight:800; color:#003B72;">
                AED <?php echo number_format($price, 2); ?>
            </span>
        </div>
        
        <div class="qty-selector" style="margin: 0 auto;">
            <button type="button" class="qty-btn" data-action="minus" data-target="<?php echo $product_id; ?>">&minus;</button>
            
            <input type="number" 
                   name="tickets[<?php echo $product_id; ?>]" 
                   id="qty-<?php echo $product_id; ?>" 
                   value="0" 
                   min="0"
                   readonly
                   class="qty-input">
            
            <button type="button" class="qty-btn" data-action="plus" data-target="<?php echo $product_id; ?>">+</button>
        </div>

    </div>
</div>