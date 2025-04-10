<?php
/**
 * Enhanced image function for WordPress
 * - Ensures proper dimensions to improve PageSpeed score
 * - Handles display dimensions vs. intrinsic dimensions
 * - Supports all image types including SVG
 * 
 * @param int           $attachment_id The ID of the image attachment
 * @param string|array  $size          Image size or attributes
 * @param array         $attr          Additional attributes
 * @return string                      HTML img element
 */
function coco_image(int $attachment_id, string|array $size = 'full', array $attr = []): string {
    // Early return if no attachment ID
    if (empty($attachment_id)) {
        return '';
    }
    
    // Handle case where $size is actually the attributes array (backward compatibility)
    if (is_array($size)) {
        $attr = $size;
        $size = 'full';
    }

    // Default attributes
    $attr = [
        'loading' => 'lazy',
        'decoding' => 'async',
        ...$attr,
    ];
    
    // Get image metadata
    $mime_type = get_post_mime_type($attachment_id);
    $image_meta = wp_get_attachment_metadata($attachment_id);
    
    // Handle SVG files specially
    if ($mime_type === 'image/svg+xml') {
        return get_svg_image($attachment_id, $attr);
    }

    // Get display dimensions
    $display_width = isset($attr['width']) && is_numeric($attr['width']) ? (int)$attr['width'] : 0;
    $display_height = isset($attr['height']) && is_numeric($attr['height']) ? (int)$attr['height'] : 0;
    
    // Find optimal image size if display dimensions specified
    $wp_size = $size;
    if (($display_width > 0 || $display_height > 0) && $size === 'full' && $image_meta) {
        $optimal_size = find_optimal_image_size($attachment_id, $display_width, $display_height, $image_meta);
        if ($optimal_size) {
            $wp_size = $optimal_size;
        }
    }
    
    // Get image source and dimensions
    $image_src = wp_get_attachment_image_src($attachment_id, $wp_size);
    if (!$image_src) {
        return '';
    }
    
    // Extract dimensions from image source
    $intrinsic_width = $image_src[1] ?? 0;
    $intrinsic_height = $image_src[2] ?? 0;
    
    // Ensure dimensions are valid
    if ($intrinsic_width <= 0 || $intrinsic_height <= 0) {
        // Try to get dimensions from metadata or file
        list($width, $height) = get_valid_dimensions($attachment_id, $image_meta);
        $intrinsic_width = $width;
        $intrinsic_height = $height;
    }
    
    // Special handling for small images (100px or less)
    $is_small_image = $intrinsic_width <= 100 || $intrinsic_height <= 100;
    if ($is_small_image) {
        $display_width = $intrinsic_width;
        $display_height = $intrinsic_height;
        $attr['loading'] = 'eager';
        $attr['class'] = ($attr['class'] ?? '') . ' wp-image-icon';
    } else {
        // Calculate display dimensions if needed
        if ($display_width <= 0 && $display_height <= 0) {
            $display_width = $intrinsic_width;
            $display_height = $intrinsic_height;
        } elseif ($display_width > 0 && $display_height <= 0 && $intrinsic_width > 0) {
            $display_height = round(($display_width / $intrinsic_width) * $intrinsic_height);
        } elseif ($display_width <= 0 && $display_height > 0 && $intrinsic_height > 0) {
            $display_width = round(($display_height / $intrinsic_height) * $intrinsic_width);
        }
    }
    
    // Set dimensions and aspect ratio
    $attr['width'] = $display_width;
    $attr['height'] = $display_height;
    
    // Remove any existing aspect-ratio to prevent duplication
    if (isset($attr['style'])) {
        $attr['style'] = preg_replace('/aspect-ratio:\s*[\d.]+;?\s*/', '', $attr['style']);
    }
    
    // Add aspect ratio only if we have valid dimensions
    if ($display_width > 0 && $display_height > 0) {
        $aspect_ratio = round($display_width / $display_height, 3);
        $attr['style'] = trim(($attr['style'] ?? '') . ' aspect-ratio: ' . $aspect_ratio . ';');
    }
    
    // Add srcset for responsive images (except small images)
    if (!$is_small_image && !isset($attr['srcset'])) {
        $srcset = wp_get_attachment_image_srcset($attachment_id, $wp_size);
        if ($srcset) {
            $attr['srcset'] = $srcset;
            if (!isset($attr['sizes'])) {
                $attr['sizes'] = $display_width > 0 
                    ? "(max-width: 767px) 100vw, {$display_width}px" 
                    : '(max-width: 767px) 100vw, 50vw';
            }
        }
    }
    
    // Generate the final image tag
    return wp_get_attachment_image($attachment_id, $wp_size, false, $attr);
}

/**
 * Get valid dimensions from various sources
 * 
 * @param int   $attachment_id The attachment ID
 * @param array|null|false $image_meta Image metadata (can be null or false)
 * @return array              Width and height
 */
function get_valid_dimensions(int $attachment_id, $image_meta = null): array {
    // Default dimensions
    $width = 800;
    $height = 600;
    
    // Try metadata first (if it's a valid array)
    if (is_array($image_meta) && isset($image_meta['width'], $image_meta['height']) && 
        $image_meta['width'] > 0 && $image_meta['height'] > 0) {
        return [(int)$image_meta['width'], (int)$image_meta['height']];
    }
    
    // Try file dimensions
    $file_path = get_attached_file($attachment_id);
    if ($file_path && file_exists($file_path)) {
        $dimensions = @getimagesize($file_path);
        if ($dimensions && $dimensions[0] > 0 && $dimensions[1] > 0) {
            return [(int)$dimensions[0], (int)$dimensions[1]];
        }
    }
    
    return [$width, $height];
}

/**
 * Finds the most appropriate image size for display dimensions
 * 
 * @param int   $attachment_id  The attachment ID
 * @param int   $display_width  Desired display width
 * @param int   $display_height Desired display height
 * @param array|null $image_meta Optional image metadata
 * @return string|null          Optimal size name or null
 */
function find_optimal_image_size(int $attachment_id, int $display_width = 0, int $display_height = 0, ?array $image_meta = null): ?string {
    // Get metadata if not provided
    if (!is_array($image_meta)) {
        $image_meta = wp_get_attachment_metadata($attachment_id);
        if (!is_array($image_meta)) {
            return null;
        }
    }
    
    // Bail if no sizes available
    if (empty($image_meta['sizes']) || !is_array($image_meta['sizes'])) {
        return null;
    }
    
    // If no dimensions requested, can't optimize
    if ($display_width <= 0 && $display_height <= 0) {
        return null;
    }
    
    // Get original dimensions
    $original_width = isset($image_meta['width']) && is_numeric($image_meta['width']) ? (int)$image_meta['width'] : 0;
    $original_height = isset($image_meta['height']) && is_numeric($image_meta['height']) ? (int)$image_meta['height'] : 0;
    
    // Calculate target dimensions (1.5x for retina displays)
    $target_width = $display_width > 0 ? $display_width * 1.5 : 0;
    $target_height = $display_height > 0 ? $display_height * 1.5 : 0;
    
    // Calculate missing dimension proportionally
    if ($target_width > 0 && $target_height === 0 && $original_width > 0 && $original_height > 0) {
        $target_height = round(($target_width / $original_width) * $original_height);
    } elseif ($target_width === 0 && $target_height > 0 && $original_width > 0 && $original_height > 0) {
        $target_width = round(($target_height / $original_height) * $original_width);
    }
    
    // If target exceeds original, use original
    if (($target_width >= $original_width && $target_width > 0) || 
        ($target_height >= $original_height && $target_height > 0)) {
        return 'full';
    }
    
    // Find best matching size
    $best_size = null;
    $closest_area_diff = PHP_INT_MAX;
    $target_area = $target_width * $target_height;
    
    foreach ($image_meta['sizes'] as $size_name => $size_data) {
        if (!is_array($size_data)) {
            continue;
        }
        
        $size_width = isset($size_data['width']) && is_numeric($size_data['width']) ? (int)$size_data['width'] : 0;
        $size_height = isset($size_data['height']) && is_numeric($size_data['height']) ? (int)$size_data['height'] : 0;
        
        // Skip invalid or too small sizes
        if ($size_width <= 0 || $size_height <= 0 ||
            ($display_width > 0 && $size_width < $display_width) || 
            ($display_height > 0 && $size_height < $display_height)) {
            continue;
        }
        
        // Find closest match by area difference
        $area_diff = abs(($size_width * $size_height) - $target_area);
        if ($area_diff < $closest_area_diff) {
            $closest_area_diff = $area_diff;
            $best_size = $size_name;
        }
    }
    
    return $best_size;
}

/**
 * Handles SVG images with proper dimensions
 */
function get_svg_image(int $attachment_id, array $attr = []): string {
    // Get SVG file data
    $svg_url = wp_get_attachment_url($attachment_id);
    $svg_path = get_attached_file($attachment_id);
    
    if (!$svg_url || !$svg_path || !file_exists($svg_path)) {
        return '';
    }
    
    // Get dimensions
    $dimensions = get_svg_dimensions($svg_path);
    $intrinsic_width = $dimensions['width'] ?? 100;
    $intrinsic_height = $dimensions['height'] ?? 100;
    
    // Get display dimensions
    $display_width = isset($attr['width']) && is_numeric($attr['width']) ? (int)$attr['width'] : 0;
    $display_height = isset($attr['height']) && is_numeric($attr['height']) ? (int)$attr['height'] : 0;
    
    // Calculate display dimensions
    if ($display_width <= 0 && $display_height <= 0) {
        $display_width = $intrinsic_width;
        $display_height = $intrinsic_height;
    } elseif ($display_width > 0 && $display_height <= 0 && $intrinsic_width > 0) {
        $display_height = round(($display_width / $intrinsic_width) * $intrinsic_height);
    } elseif ($display_width <= 0 && $display_height > 0 && $intrinsic_height > 0) {
        $display_width = round(($display_height / $intrinsic_height) * $intrinsic_width);
    }
    
    // Set dimensions and aspect ratio
    $attr['width'] = $display_width;
    $attr['height'] = $display_height;
    
    // Remove any existing aspect-ratio to prevent duplication
    if (isset($attr['style'])) {
        $attr['style'] = preg_replace('/aspect-ratio:\s*[\d.]+;?\s*/', '', $attr['style']);
    }
    
    // Add aspect ratio
    if ($display_width > 0 && $display_height > 0) {
        $aspect_ratio = round($display_width / $display_height, 3);
        $attr['style'] = trim(($attr['style'] ?? '') . ' aspect-ratio: ' . $aspect_ratio . ';');
    }
    
    // Set alt and src
    $attr['alt'] = $attr['alt'] ?? get_post_meta($attachment_id, '_wp_attachment_image_alt', true) ?: get_the_title($attachment_id);
    $attr['src'] = $svg_url;
    $attr['role'] = 'img';
    
    // Build HTML tag
    $html = '<img';
    foreach ($attr as $name => $value) {
        $html .= ' ' . $name . '="' . esc_attr($value) . '"';
    }
    $html .= '>';
    
    return $html;
}

/**
 * Gets dimensions from an SVG file
 */
function get_svg_dimensions(string $svg_path): array {
    // Default dimensions
    $dimensions = ['width' => 100, 'height' => 100];
    
    // Check if file exists
    if (!file_exists($svg_path)) {
        return $dimensions;
    }
    
    // Get SVG content
    $svg_content = @file_get_contents($svg_path);
    if (!$svg_content) {
        return $dimensions;
    }
    
    // Check for width/height attributes
    $width = 0;
    $height = 0;
    
    if (preg_match('/width="([^"]*)"/', $svg_content, $width_match)) {
        $width = parse_svg_dimension($width_match[1]);
    }
    
    if (preg_match('/height="([^"]*)"/', $svg_content, $height_match)) {
        $height = parse_svg_dimension($height_match[1]);
    }
    
    if ($width > 0 && $height > 0) {
        return ['width' => $width, 'height' => $height];
    }
    
    // Check for viewBox
    if (preg_match('/viewBox="([^"]*)"/', $svg_content, $viewbox_match)) {
        $viewbox_parts = preg_split('/[\s,]+/', trim($viewbox_match[1]));
        
        if (count($viewbox_parts) === 4) {
            $vb_width = (float) $viewbox_parts[2];
            $vb_height = (float) $viewbox_parts[3];
            
            if ($vb_width > 0 && $vb_height > 0) {
                return ['width' => (int) $vb_width, 'height' => (int) $vb_height];
            }
        }
    }
    
    return $dimensions;
}

/**
 * Parse SVG dimension value
 */
function parse_svg_dimension($value): int {
    if (empty($value) || !is_string($value)) {
        return 0;
    }
    
    // If numeric, return as integer
    if (is_numeric($value)) {
        return (int) $value;
    }
    
    // Parse with units
    if (preg_match('/^([\d.]+)(px|em|rem|pt|pc|mm|cm|in|%)?$/i', $value, $matches)) {
        $num = (float) $matches[1];
        $unit = strtolower($matches[2] ?? 'px');
        
        return match($unit) {
            'px' => (int) $num,
            'em', 'rem' => (int) ($num * 16),
            'pt' => (int) ($num * 1.333),
            'pc' => (int) ($num * 16),
            'mm' => (int) ($num * 3.779),
            'cm' => (int) ($num * 37.795),
            'in' => (int) ($num * 96),
            '%' => 0,
            default => (int) $num,
        };
    }
    
    return 0;
}

/**
 * Add filter to ensure all WordPress images have proper dimensions
 */
function ensure_all_images_have_proper_dimensions() {
    add_filter('wp_get_attachment_image_attributes', function($attr, $attachment, $size) {
        // Get dimensions
        $image_src = wp_get_attachment_image_src($attachment->ID, $size);
        $intrinsic_width = $image_src[1] ?? 0;
        $intrinsic_height = $image_src[2] ?? 0;
        
        // Ensure valid dimensions
        if ($intrinsic_width <= 0 || $intrinsic_height <= 0) {
            list($width, $height) = get_valid_dimensions($attachment->ID);
            $intrinsic_width = $width;
            $intrinsic_height = $height;
        }
        
        // Get display dimensions
        $display_width = isset($attr['width']) && is_numeric($attr['width']) ? (int)$attr['width'] : 0;
        $display_height = isset($attr['height']) && is_numeric($attr['height']) ? (int)$attr['height'] : 0;
        
        // Calculate display dimensions if needed
        if ($display_width <= 0 && $display_height <= 0) {
            $display_width = $intrinsic_width;
            $display_height = $intrinsic_height;
        } elseif ($display_width > 0 && $display_height <= 0 && $intrinsic_width > 0) {
            $display_height = round(($display_width / $intrinsic_width) * $intrinsic_height);
        } elseif ($display_width <= 0 && $display_height > 0 && $intrinsic_height > 0) {
            $display_width = round(($display_height / $intrinsic_height) * $intrinsic_width);
        }
        
        // Set dimensions
        $attr['width'] = $display_width;
        $attr['height'] = $display_height;
        
        // Remove any existing aspect-ratio to prevent duplication
        if (isset($attr['style'])) {
            $attr['style'] = preg_replace('/aspect-ratio:\s*[\d.]+;?\s*/', '', $attr['style']);
        }
        
        // Add aspect ratio
        if ($display_width > 0 && $display_height > 0) {
            $aspect_ratio = round($display_width / $display_height, 3);
            $attr['style'] = trim(($attr['style'] ?? '') . ' aspect-ratio: ' . $aspect_ratio . ';');
        }
        
        return $attr;
    }, 10, 3);
}

// Initialize the filter
ensure_all_images_have_proper_dimensions();

/**
 * Usage Examples:
 * //Images Dimensions Handler create new file /inc/img_handler.php
* if ( file_exists( get_template_directory() . '/inc/img_handler.php' ) ) {
*    require_once get_template_directory() . '/inc/img_handler.php';
* }
* 
 * // Original WordPress style
 * echo coco_image($image_id, 'medium', ['class' => 'your-class']);
 * 
 * // With specific dimensions
 * echo coco_image($image_id, [
 *     'width' => 649,
 *     'height' => 406,
 *     'class' => 'your-class'
 * ]);
 */
