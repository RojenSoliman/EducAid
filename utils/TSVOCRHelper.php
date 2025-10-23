<?php
/**
 * TSV Data Utility - Helper functions for working with OCR TSV data
 */

class TSVOCRHelper {
    
    /**
     * Load and parse TSV file
     */
    public static function loadTSV($tsvFilePath) {
        if (!file_exists($tsvFilePath)) {
            return ['success' => false, 'error' => 'TSV file not found'];
        }
        
        $content = file_get_contents($tsvFilePath);
        $lines = preg_split('/\r?\n/', $content);
        
        if (count($lines) < 2) {
            return ['success' => false, 'error' => 'TSV file is empty'];
        }
        
        // Remove header
        array_shift($lines);
        
        $data = [];
        foreach ($lines as $line) {
            if (!trim($line)) continue;
            
            $cols = explode("\t", $line);
            if (count($cols) >= 12) {
                $data[] = [
                    'level' => (int)($cols[0] ?? 0),
                    'page_num' => (int)($cols[1] ?? 0),
                    'block_num' => (int)($cols[2] ?? 0),
                    'par_num' => (int)($cols[3] ?? 0),
                    'line_num' => (int)($cols[4] ?? 0),
                    'word_num' => (int)($cols[5] ?? 0),
                    'left' => (int)($cols[6] ?? 0),
                    'top' => (int)($cols[7] ?? 0),
                    'width' => (int)($cols[8] ?? 0),
                    'height' => (int)($cols[9] ?? 0),
                    'conf' => is_numeric($cols[10]) ? (float)$cols[10] : null,
                    'text' => trim($cols[11] ?? '')
                ];
            }
        }
        
        return [
            'success' => true,
            'data' => $data,
            'total_words' => count($data)
        ];
    }
    
    /**
     * Get only word-level entries (level 5)
     */
    public static function getWords($tsvData) {
        return array_filter($tsvData, function($entry) {
            return $entry['level'] === 5 && !empty($entry['text']);
        });
    }
    
    /**
     * Calculate quality metrics
     */
    public static function calculateQuality($tsvData) {
        $words = self::getWords($tsvData);
        
        if (empty($words)) {
            return [
                'total_words' => 0,
                'avg_confidence' => 0,
                'quality_score' => 0,
                'low_confidence_count' => 0
            ];
        }
        
        $totalConf = 0;
        $count = 0;
        $lowConfCount = 0;
        
        foreach ($words as $word) {
            if ($word['conf'] !== null && $word['conf'] >= 0) {
                $totalConf += $word['conf'];
                $count++;
                
                if ($word['conf'] < 70) {
                    $lowConfCount++;
                }
            }
        }
        
        $avgConf = $count > 0 ? round($totalConf / $count, 2) : 0;
        $qualityScore = $count > 0 ? 100 - (($lowConfCount / $count) * 100) : 0;
        
        return [
            'total_words' => count($words),
            'avg_confidence' => $avgConf,
            'quality_score' => round($qualityScore, 1),
            'low_confidence_count' => $lowConfCount,
            'low_confidence_percentage' => $count > 0 ? round(($lowConfCount / $count) * 100, 1) : 0
        ];
    }
    
    /**
     * Find words with low confidence
     */
    public static function findLowConfidenceWords($tsvData, $threshold = 70) {
        $words = self::getWords($tsvData);
        
        return array_values(array_filter($words, function($word) use ($threshold) {
            return $word['conf'] !== null && $word['conf'] < $threshold;
        }));
    }
    
    /**
     * Get text from specific region (coordinates)
     */
    public static function getTextInRegion($tsvData, $left, $top, $width, $height) {
        $words = self::getWords($tsvData);
        
        $regionWords = array_filter($words, function($word) use ($left, $top, $width, $height) {
            $wordRight = $word['left'] + $word['width'];
            $wordBottom = $word['top'] + $word['height'];
            $regionRight = $left + $width;
            $regionBottom = $top + $height;
            
            // Check if word overlaps with region
            return !($wordRight < $left || $word['left'] > $regionRight ||
                     $wordBottom < $top || $word['top'] > $regionBottom);
        });
        
        // Sort by position (top to bottom, left to right)
        usort($regionWords, function($a, $b) {
            if (abs($a['top'] - $b['top']) < 20) {
                return $a['left'] <=> $b['left'];
            }
            return $a['top'] <=> $b['top'];
        });
        
        return array_map(function($w) { return $w['text']; }, $regionWords);
    }
    
    /**
     * Get text by block number (useful for extracting specific sections)
     */
    public static function getTextByBlock($tsvData, $blockNum) {
        $words = array_filter(self::getWords($tsvData), function($word) use ($blockNum) {
            return $word['block_num'] === $blockNum;
        });
        
        // Sort by line and word position
        usort($words, function($a, $b) {
            if ($a['line_num'] !== $b['line_num']) {
                return $a['line_num'] <=> $b['line_num'];
            }
            return $a['word_num'] <=> $b['word_num'];
        });
        
        return implode(' ', array_map(function($w) { return $w['text']; }, $words));
    }
    
    /**
     * Generate bounding box HTML for visualization
     */
    public static function generateBoundingBoxes($tsvData, $imageWidth, $imageHeight) {
        $words = self::getWords($tsvData);
        $html = '';
        
        foreach ($words as $word) {
            $conf = $word['conf'] ?? 0;
            
            // Color code by confidence
            if ($conf >= 85) {
                $color = 'rgba(0, 255, 0, 0.3)'; // Green
            } elseif ($conf >= 70) {
                $color = 'rgba(255, 255, 0, 0.3)'; // Yellow
            } else {
                $color = 'rgba(255, 0, 0, 0.3)'; // Red
            }
            
            $left = ($word['left'] / $imageWidth) * 100;
            $top = ($word['top'] / $imageHeight) * 100;
            $width = ($word['width'] / $imageWidth) * 100;
            $height = ($word['height'] / $imageHeight) * 100;
            
            $html .= sprintf(
                '<div class="ocr-box" style="position:absolute; left:%.2f%%; top:%.2f%%; width:%.2f%%; height:%.2f%%; background:%s; border:1px solid #000;" title="%s (%.1f%%)"></div>',
                $left, $top, $width, $height, $color, htmlspecialchars($word['text']), $conf
            );
        }
        
        return $html;
    }
    
    /**
     * Export TSV data to JSON
     */
    public static function toJSON($tsvData, $prettyPrint = true) {
        $flags = $prettyPrint ? JSON_PRETTY_PRINT : 0;
        return json_encode($tsvData, $flags);
    }
    
    /**
     * Search for text pattern and return matches with coordinates
     */
    public static function searchText($tsvData, $pattern, $caseSensitive = false) {
        $words = self::getWords($tsvData);
        $matches = [];
        
        foreach ($words as $word) {
            $text = $caseSensitive ? $word['text'] : strtolower($word['text']);
            $searchPattern = $caseSensitive ? $pattern : strtolower($pattern);
            
            if (strpos($text, $searchPattern) !== false) {
                $matches[] = $word;
            }
        }
        
        return $matches;
    }
}

// Example usage:
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    echo "<h2>TSV OCR Helper - Usage Examples</h2>";
    
    // Example 1: Load TSV
    $tsvFile = __DIR__ . '/assets/uploads/temp/grades/filename.pdf.tsv';
    
    if (file_exists($tsvFile)) {
        $result = TSVOCRHelper::loadTSV($tsvFile);
        
        if ($result['success']) {
            echo "<h3>1. Basic Stats</h3>";
            echo "Total entries: " . $result['total_words'] . "<br/>";
            
            // Example 2: Quality Analysis
            $quality = TSVOCRHelper::calculateQuality($result['data']);
            echo "<h3>2. Quality Metrics</h3>";
            echo "Total words: " . $quality['total_words'] . "<br/>";
            echo "Average confidence: " . $quality['avg_confidence'] . "%<br/>";
            echo "Quality score: " . $quality['quality_score'] . "/100<br/>";
            echo "Low confidence words: " . $quality['low_confidence_count'] . 
                 " (" . $quality['low_confidence_percentage'] . "%)<br/>";
            
            // Example 3: Find low confidence words
            $lowConf = TSVOCRHelper::findLowConfidenceWords($result['data'], 80);
            echo "<h3>3. Low Confidence Words (&lt; 80%)</h3>";
            foreach (array_slice($lowConf, 0, 10) as $word) {
                echo sprintf(
                    "- '%s' at (%d, %d) - %.1f%% confidence<br/>",
                    htmlspecialchars($word['text']),
                    $word['left'],
                    $word['top'],
                    $word['conf']
                );
            }
            
            // Example 4: Search for text
            $gradeMatches = TSVOCRHelper::searchText($result['data'], 'grade');
            echo "<h3>4. Search Results for 'grade'</h3>";
            echo "Found " . count($gradeMatches) . " matches<br/>";
            foreach (array_slice($gradeMatches, 0, 5) as $match) {
                echo "- " . htmlspecialchars($match['text']) . " (conf: " . round($match['conf'], 1) . "%)<br/>";
            }
            
        } else {
            echo "Error: " . $result['error'];
        }
    } else {
        echo "<p>Example TSV file not found. Upload a grades document first.</p>";
        echo "<p>Expected location: <code>" . htmlspecialchars($tsvFile) . "</code></p>";
    }
}
?>
