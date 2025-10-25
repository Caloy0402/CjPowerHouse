<?php

function displayStars($rating) {
    $fullStars = floor($rating);
    $halfStar = ($rating - $fullStars >= 0.5); // Check for a half star
    $emptyStars = 5 - $fullStars - ($halfStar ? 1 : 0); // Account for a possible half star

    $output = '';

    // Full stars
    for ($i = 0; $i < $fullStars; $i++) {
        $output .= '<i class="fas fa-star"></i>'; // Font Awesome full star icon
    }

    // Half star, if needed
    if ($halfStar) {
        $output .= '<i class="fas fa-star-half-alt"></i>'; // Font Awesome half star icon
    }

    // Empty stars
    for ($i = 0; $i < $emptyStars; $i++) {
        $output .= '<i class="far fa-star"></i>'; // Font Awesome empty star icon
    }

    return $output;
}
?>