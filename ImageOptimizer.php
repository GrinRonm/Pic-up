<?php
/**
 * Image Optimization Helper
 * Compresses and optimizes images
 */

class ImageOptimizer {
    private $config;
    
    public function __construct($config) {
        $this->config = $config;
    }
    
    /**
     * Optimize image file
     * @param string $source_path Path to original image
     * @param string $dest_path Where to save optimized image
     * @param int $quality JPEG quality (1-100)
     * @return bool
     */
    public function optimize($source_path, $dest_path, $quality = 85) {
        if (!file_exists($source_path)) {
            return false;
        }
        
        $mime_type = mime_content_type($source_path);
        
        switch ($mime_type) {
            case 'image/jpeg':
                return $this->optimizeJpeg($source_path, $dest_path, $quality);
            case 'image/png':
                return $this->optimizePng($source_path, $dest_path);
            case 'image/webp':
                return $this->optimizeWebp($source_path, $dest_path, $quality);
            case 'image/gif':
                return copy($source_path, $dest_path);
            default:
                return copy($source_path, $dest_path);
        }
    }

    /**
     * Create a smaller version of the image
     */
    public function resize($source_path, $dest_path, $max_width = 800, $max_height = 800, $quality = 80) {
        if (!file_exists($source_path) || !extension_loaded('gd')) {
            return false;
        }

        $info = getimagesize($source_path);
        if (!$info) return false;

        $width = $info[0];
        $height = $info[1];
        $mime = $info['mime'];

        // Calculate aspect ratio
        $ratio = $width / $height;
        if ($width > $max_width || $height > $max_height) {
            if ($max_width / $max_height > $ratio) {
                $max_width = $max_height * $ratio;
            } else {
                $max_height = $max_width / $ratio;
            }
        } else {
            // No need to resize, just optimize
            return $this->optimize($source_path, $dest_path, $quality);
        }

        // Create resource
        switch ($mime) {
            case 'image/jpeg': $src = imagecreatefromjpeg($source_path); break;
            case 'image/png': $src = imagecreatefrompng($source_path); break;
            case 'image/webp': $src = imagecreatefromwebp($source_path); break;
            case 'image/gif': $src = imagecreatefromgif($source_path); break;
            default: return false;
        }

        if (!$src) return false;

        $dst = imagecreatetruecolor($max_width, $max_height);
        
        // Handle transparency
        if ($mime === 'image/png' || $mime === 'image/webp' || $mime === 'image/gif') {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
        }

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $max_width, $max_height, $width, $height);

        // Save
        switch ($mime) {
            case 'image/jpeg': $res = imagejpeg($dst, $dest_path, $quality); break;
            case 'image/png': $res = imagepng($dst, $dest_path, 9); break;
            case 'image/webp': $res = imagewebp($dst, $dest_path, $quality); break;
            case 'image/gif': $res = imagegif($dst, $dest_path); break;
            default: $res = false;
        }

        imagedestroy($src);
        imagedestroy($dst);
        return $res;
    }
    
    private function optimizeJpeg($source, $dest, $quality) {
        if (!extension_loaded('gd')) {
            return copy($source, $dest);
        }
        
        $image = imagecreatefromjpeg($source);
        if (!$image) return copy($source, $dest);
        
        $result = imagejpeg($image, $dest, $quality);
        imagedestroy($image);
        return $result !== false;
    }
    
    private function optimizePng($source, $dest) {
        if (!extension_loaded('gd')) {
            return copy($source, $dest);
        }
        
        $image = imagecreatefrompng($source);
        if (!$image) return copy($source, $dest);
        
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $result = imagepng($image, $dest, 9);
        imagedestroy($image);
        return $result !== false;
    }
    
    private function optimizeWebp($source, $dest, $quality) {
        if (!extension_loaded('gd')) {
            return copy($source, $dest);
        }
        
        $image = imagecreatefromwebp($source);
        if (!$image) return copy($source, $dest);
        
        $result = imagewebp($image, $dest, $quality);
        imagedestroy($image);
        return $result !== false;
    }
    
    /**
     * Get file size savings
     */
    public function getSavings($original_size, $optimized_size) {
        if ($original_size === 0) return 0;
        return round(($original_size - $optimized_size) / $original_size * 100, 1);
    }
}
