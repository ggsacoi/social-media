<?php
// moderation.php - Service de modération de contenu PHP

/**
 * Vérifie localement si un texte contient des mots-clés interdits (repli sécurisé)
 */
function containsBlockedTextLocal($text) {
    if (empty($text)) return false;
    
    $blockedKeywords = [
        'porn', 'porno', 'pornographie', 'hentai', 'sex', 'sexe', 'sexuel', 'sexual', 'xxx', 'adult',
        'bdsm', 'erotique', 'erotic', 'erotisme', 'nude', 'nu', 'nue', 'naked', 'seins', 'boobs',
        'tits', 'nichon', 'cul', 'fellation', 'fellatio', 'masturb', 'masturbation', 'penetr', 'penis',
        'pipe', 'bite', 'couilles', 'chatte', 'vagin', 'anus', 'pussy', 'cock', 'ass', 'fetish', 'cum'
    ];
    
    $lower = strtolower($text);
    // Remplacer les caractères non-alphanumériques par des espaces pour éviter les contournements
    $clean = preg_replace('/[^a-z0-9\s]/', ' ', $lower);
    
    foreach ($blockedKeywords as $keyword) {
        if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/', $clean)) {
            return true;
        }
    }
    return false;
}

/**
 * Modère le contenu (texte et/ou image) en interrogeant le service Node.js
 */
function moderateContent($text, $filePath = '') {
    $absoluteFilePath = '';
    if (!empty($filePath)) {
        if (file_exists($filePath)) {
            $absoluteFilePath = realpath($filePath);
        } else {
            $absoluteFilePath = realpath(__DIR__ . '/' . $filePath);
        }
    }

    $url = 'http://localhost:3000/api/moderate';
    $data = array(
        'text' => $text,
        'filePath' => $absoluteFilePath
    );

    $options = array(
        'http' => array(
            'header'  => "Content-type: application/json\r\n",
            'method'  => 'POST',
            'content' => json_encode($data),
            'timeout' => 15 // Timeout de 15 secondes pour l'analyse d'image
        )
    );

    $context  = stream_context_create($options);
    $result = @file_get_contents($url, false, $context);

    if ($result === FALSE) {
        error_log("⚠️ Service de modération Node.js indisponible. Repli sur le filtre local.");
        if (containsBlockedTextLocal($text)) {
            return array('safe' => false, 'reason' => 'Contenu textuel inapproprié détecté (Filtre local).');
        }
        return array('safe' => true);
    }

    $decoded = json_decode($result, true);
    if (!is_array($decoded) || !isset($decoded['safe'])) {
        error_log("⚠️ Réponse de modération invalide du serveur Node.js. Repli sécurisé (safe).");
        return array('safe' => true);
    }

    return $decoded;
}
?>
