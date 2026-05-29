// livestream.js - Version Sans Conflits avec CSRF
// Ce script fonctionne indépendamment de user_page.js

(function() {
    'use strict';
    
    // Utiliser des noms UNIQUES pour éviter tout conflit
    const __LIVESTREAM_STATE__ = {
        stream: null,
        container: null,
        video: null,
        sessionId: null,
        isActive: false
    };

    function __log(message) {
        console.log('[LIVESTREAM]', message);
    }

    // Récupérer le token CSRF depuis la page
    function __getCsrfToken() {
        // D'abord chercher dans window.csrfToken (exposé globalement)
        if (window.csrfToken && window.csrfToken.trim() !== '') {
            __log('Token CSRF trouvé dans window.csrfToken: ' + window.csrfToken.substring(0, 20) + '...');
            return window.csrfToken;
        }
        
        __log('window.csrfToken vide ou undefined: ' + JSON.stringify(window.csrfToken));
        
        // Sinon chercher dans les inputs hidden
        const csrfInput = document.querySelector('input[name="csrf_token"]');
        if (csrfInput && csrfInput.value && csrfInput.value.trim() !== '') {
            __log('Token CSRF trouvé dans input: ' + csrfInput.value.substring(0, 20) + '...');
            return csrfInput.value;
        }
        
        __log('❌ ERREUR: Aucun token CSRF trouvé!');
        return '';
    }

    async function __startCamera() {
        __log('Début du démarrage...');
        
        if (!navigator.mediaDevices?.getUserMedia) {
            __log('❌ getUserMedia non supporté');
            alert('Caméra non supportée par votre navigateur.');
            return;
        }

        try {
            // 1. Enregistrer en BD
            const csrfToken = __getCsrfToken();
            __log('CSRF Token trouvé: ' + csrfToken);
            __log('window.csrfToken: ' + (window.csrfToken || 'undefined'));
            
            __log('Appel serveur pour enregistrer la session...');
            const body = 'action=start&title=Livestream&csrf_token=' + encodeURIComponent(csrfToken);
            __log('Body envoyé: ' + body);
            
            const response = await fetch('livestream_handler.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-Token': csrfToken
                },
                body: body
            });

            const respText = await response.text();
            __log('Réponse brute serveur (tronc): ' + (respText ? respText.substring(0,200) : '[empty]'));

            if (!response.ok) {
                __log('❌ Erreur HTTP: ' + response.status);
                alert('Erreur serveur: ' + response.status);
                return;
            }

            let data;
            try {
                data = JSON.parse(respText);
            } catch (e) {
                __log('❌ Impossible de parser JSON serveur: ' + e.message);
                __log('=== Contenu serveur complet ===\n' + respText);
                alert('Erreur serveur inattendue — voir console pour détails.');
                return;
            }

            __log('Réponse serveur: ' + JSON.stringify(data));
            
            if (!data.success) {
                __log('❌ Erreur backend: ' + data.error);
                alert('Erreur serveur: ' + (data.error || 'Impossible de démarrer'));
                return;
            }

            __log('✅ Session créée avec ID: ' + data.livestream_id);

            // 2. Demander caméra
            __log('Demande d\'accès à la caméra...');
            let stream;
            try {
                stream = await navigator.mediaDevices.getUserMedia({ 
                    video: {
                        width: { ideal: 1280 },
                        height: { ideal: 720 },
                        facingMode: 'user'
                    },
                    audio: true
                });
                __log('✅ Caméra autorisée');
            } catch (error) {
                __log('❌ Erreur caméra: ' + error.name + ' - ' + error.message);
                
                let msg = '';
                if (error.name === 'NotAllowedError')
                    msg = 'Permission caméra refusée. Autorisez dans les paramètres du navigateur.';
                else if (error.name === 'NotFoundError')
                    msg = 'Aucune caméra détectée. Vérifiez que votre caméra est connectée.';
                else if (error.name === 'NotReadableError')
                    msg = 'La caméra est utilisée par une autre application. Fermez-la et réessayez.';
                else
                    msg = 'Erreur caméra: ' + error.message;
                
                alert(msg);
                
                // Annuler la session BD
                try {
                    const cancelResp = await fetch('livestream_handler.php', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 
                            'Content-Type': 'application/x-www-form-urlencoded',
                            'X-CSRF-Token': csrfToken
                        },
                        body: 'action=stop&livestream_id=' + data.livestream_id + '&csrf_token=' + encodeURIComponent(csrfToken)
                    });
                    const cancelText = await cancelResp.text();
                    __log('Annulation réponse brute: ' + (cancelText ? cancelText.substring(0,200) : '[empty]'));
                } catch (e) {
                    __log('Erreur annulation: ' + e.message);
                }
                
                throw error;
            }

            // 3. Créer modal
            __log('Création du modal...');
            const overlay = document.createElement('div');
            overlay.id = 'liveStreamVideoModal';
            overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.75);z-index:10050;display:flex;align-items:center;justify-content:center;';
            
            const modal = document.createElement('div');
            modal.id = 'liveStreamModalContent';
            modal.style.cssText = 'width:65vw;height:65vh;background:#000;border-radius:18px;position:relative;overflow:hidden;';
            
            const video = document.createElement('video');
            video.style.cssText = 'width:100%;height:100%;object-fit:cover;';
            video.controls = false;
            modal.appendChild(video);
            
            const closeBtn = document.createElement('button');
            closeBtn.innerHTML = '&times;';
            closeBtn.style.cssText = 'position:absolute;top:10px;right:10px;width:40px;height:40px;background:rgba(255,0,0,0.7);border:none;color:white;font-size:30px;cursor:pointer;border-radius:50%;z-index:10051;';
            closeBtn.addEventListener('click', __stopCamera);
            modal.appendChild(closeBtn);
            
            overlay.addEventListener('click', (e) => {
                if (e.target === overlay) __stopCamera();
            });
            
            overlay.appendChild(modal);
            document.body.appendChild(overlay);
            
            // 4. Afficher vidéo
            video.srcObject = stream;
            video.onloadedmetadata = function() {
                __log('✅ Vidéo prête');
                video.play().catch(e => __log('Erreur play: ' + e.message));
            };

            // 5. Démarrer le broadcast WebRTC si possible
            if (window.WebRTCClient && typeof window.WebRTCClient.connect === 'function') {
                try {
                    const userId = window.currentUserId;
                    const username = window.currentUserName;
                    if (!userId || !username) {
                        __log('⚠️ userId ou username manquant pour WebRTC');
                    } else {
                        __log('Connexion au serveur WebRTC et démarrage du broadcast pour userId: ' + userId);
                                        await window.WebRTCClient.connect();
                                        await window.WebRTCClient.startBroadcaster(userId, username, stream);
                        __log('✅ Broadcast WebRTC démarré');
                    }
                } catch (webrtcError) {
                    __log('⚠️ WebRTC erreur (non bloquant): ' + (webrtcError.message || webrtcError));
                }
            } else {
                __log('⚠️ WebRTCClient non disponible - broadcast local seulement');
            }

            // 5. Mettre à jour état
            __LIVESTREAM_STATE__.stream = stream;
            __LIVESTREAM_STATE__.container = overlay;
            __LIVESTREAM_STATE__.video = video;
            __LIVESTREAM_STATE__.sessionId = data.livestream_id;
            __LIVESTREAM_STATE__.isActive = true;
            
            // 6. Mettre à jour bouton (supporte l'ancien id `liveStreamButton` et le nouveau `ownLiveButton`)
            const btn = document.querySelector('#ownLiveButton, #liveStreamButton');
            if (btn) {
                btn.title = 'Arrêter le stream';
                btn.style.background = '#4caf50';
            }
            
            __log('✅ Livestream démarré avec succès!');
            
        } catch (error) {
            __log('❌ Erreur générale: ' + error.message);
            __LIVESTREAM_STATE__.isActive = false;
        }
    }

    async function __stopCamera() {
        __log('Arrêt du livestream...');
        
        if (!__LIVESTREAM_STATE__.stream) {
            __log('⚠ Pas de stream actif');
            return;
        }
        
        // Arrêter tracks
        __LIVESTREAM_STATE__.stream.getTracks().forEach(track => {
            __log('Arrêt du track: ' + track.kind);
            track.stop();
        });
        
        // Nettoyer vidéo
        if (__LIVESTREAM_STATE__.video) {
            __LIVESTREAM_STATE__.video.srcObject = null;
        }
        
        // Supprimer modal
        if (__LIVESTREAM_STATE__.container) {
            __LIVESTREAM_STATE__.container.remove();
            __log('✅ Modal supprimé');
        }

        // Stop WebRTC broadcast si actif
        if (window.WebRTCClient && typeof window.WebRTCClient.stop === 'function') {
            try {
                __log('Arrêt du broadcast WebRTC');
                await window.WebRTCClient.stop();
                __log('✅ WebRTC stopped');
            } catch (err) {
                __log('⚠️ Erreur WebRTC stop (non bloquant): ' + (err.message || err));
            }
        }

        // Enregistrer arrêt en BD
        if (__LIVESTREAM_STATE__.sessionId) {
            try {
                const csrfToken = __getCsrfToken();
                __log('Appel serveur pour arrêter...');
                try {
                    const stopResp = await fetch('livestream_handler.php', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 
                            'Content-Type': 'application/x-www-form-urlencoded',
                            'X-CSRF-Token': csrfToken
                        },
                        body: 'action=stop&livestream_id=' + __LIVESTREAM_STATE__.sessionId + '&csrf_token=' + encodeURIComponent(csrfToken)
                    });
                    const stopText = await stopResp.text();
                    __log('Stop réponse brute: ' + (stopText ? stopText.substring(0,200) : '[empty]'));
                    if (stopResp.ok) {
                        let stopData;
                        try {
                            stopData = JSON.parse(stopText);
                            __log('✅ Session arrêtée: ' + JSON.stringify(stopData));
                        } catch (e) {
                            __log('❌ Impossible de parser JSON arrêt: ' + e.message);
                        }
                    } else {
                        __log('❌ Erreur HTTP stop: ' + stopResp.status);
                    }
                } catch (error) {
                    __log('❌ Erreur arrêt: ' + error.message);
                }
            } catch (error) {
                __log('❌ Erreur arrêt: ' + error.message);
            }
        }

        // Réinitialiser
        __LIVESTREAM_STATE__.stream = null;
        __LIVESTREAM_STATE__.container = null;
        __LIVESTREAM_STATE__.video = null;
        __LIVESTREAM_STATE__.sessionId = null;
        __LIVESTREAM_STATE__.isActive = false;
        
        // Mettre à jour bouton (supporte l'ancien id `liveStreamButton` et le nouveau `ownLiveButton`)
        const btn = document.querySelector('#ownLiveButton, #liveStreamButton');
        if (btn) {
            btn.title = 'Démarrer le stream';
            btn.style.background = 'red';
        }
        
        __log('✅ Livestream arrêté');
    }

    function __sendUnloadStop() {
        if (!__LIVESTREAM_STATE__.isActive || !__LIVESTREAM_STATE__.sessionId) {
            return;
        }

        const csrfToken = __getCsrfToken();
        const params = new URLSearchParams();
        params.append('action', 'stop');
        params.append('livestream_id', __LIVESTREAM_STATE__.sessionId);
        params.append('csrf_token', csrfToken);

        const blob = new Blob([params.toString()], { type: 'application/x-www-form-urlencoded;charset=UTF-8' });
        const sent = navigator.sendBeacon && navigator.sendBeacon('livestream_handler.php', blob);
        __log('Tentative d\'arrêt sur fermeture d\'onglet via sendBeacon : ' + (sent ? 'envoyée' : 'non envoyée'));
    }

    function __toggleLiveStream() {
        if (__LIVESTREAM_STATE__.isActive) {
            __stopCamera();
        } else {
            __startCamera();
        }
    }

    // Initialiser
    function __init() {
        const btn = document.querySelector('#ownLiveButton, #liveStreamButton');
        __log('Recherche du bouton (ownLiveButton|liveStreamButton)... Trouvé: ' + !!btn);
        
        if (btn) {
            btn.addEventListener('click', __toggleLiveStream);
            btn.title = 'Démarrer le stream';
        }

        window.addEventListener('beforeunload', __sendUnloadStop);
        window.addEventListener('unload', __sendUnloadStop);
        
        // Export globale pour console
        window.livestreamDebug = {
            state: () => __LIVESTREAM_STATE__,
            start: __startCamera,
            stop: __stopCamera,
            log: (msg) => __log(msg),
            csrfToken: __getCsrfToken
        };
        
        __log('Script initialisé');
    }

    // Attendre DOM
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', __init);
    } else {
        __init();
    }

})();
