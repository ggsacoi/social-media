 const inputField = document.getElementById('username_destinataire');
            const resultsContainer = document.getElementById('searchResults');

            function loadConversation(username) {
                if (!username) return;
                const url = new URL(window.location.href);
                url.searchParams.set('with', username);
                url.searchParams.delete('page');
                window.location.href = url.pathname + '?' + url.searchParams.toString();
            }

            if (inputField && resultsContainer) {
                inputField.addEventListener('input', function() {
                    const query = this.value.trim();
                    if (query.length < 1) {
                        resultsContainer.classList.remove('active');
                        return;
                    }

                    fetch('search_users.php?q=' + encodeURIComponent(query))
                        .then(response => response.json())
                        .then(data => {
                            resultsContainer.innerHTML = '';
                            if (data.length > 0) {
                                data.forEach(user => {
                                    const item = document.createElement('div');
                                    item.className = 'search-result-item';
                                    item.innerHTML = `
                                        <img src="${user.profile_pic}" alt="Avatar">
                                        <span>${user.username}</span>
                                    `;
                                    item.addEventListener('click', function() {
                                        inputField.value = user.username;
                                        resultsContainer.classList.remove('active');
                                        loadConversation(user.username);
                                    });
                                    resultsContainer.appendChild(item);
                                });
                                resultsContainer.classList.add('active');
                            } else {
                                resultsContainer.classList.remove('active');
                            }
                        });
                });

                inputField.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') {
                        const username = this.value.trim();
                        if (username !== '') {
                            e.preventDefault();
                            loadConversation(username);
                        }
                    }
                });
            }

            document.addEventListener('click', function(e) {
                if (inputField && resultsContainer) {
                    if (e.target !== inputField && !resultsContainer.contains(e.target)) {
                        resultsContainer.classList.remove('active');
                    }
                }
            });

            const unreadBubble = document.getElementById('unreadNotificationBubble');
            const unreadPopup = document.getElementById('unreadPopup');
            const unreadPopupContent = document.getElementById('unreadPopupContent');

            function renderUnreadPopup(users) {
                if (!unreadPopupContent) return;
                if (users.length === 0) {
                    unreadPopupContent.innerHTML = '<div style="padding:12px;color:#666;">Aucun nouveau message non lu.</div>';
                    return;
                }
                unreadPopupContent.innerHTML = users.map(user => `
                    <div class="unread-user-item" data-username="${user.username}">
                        <span>${user.username}</span>
                        <span class="unread-user-count">${user.count}</span>
                    </div>
                `).join('');
                unreadPopupContent.querySelectorAll('.unread-user-item').forEach(item => {
                    item.addEventListener('click', () => {
                        const username = item.dataset.username;
                        window.location.href = `user_page.php?with=${encodeURIComponent(username)}`;
                    });
                });
            }

            function refreshUnreadNotifications() {
                if (!unreadBubble) return;
                fetch('user_page.php?unread_notifications=1')
                    .then(response => response.json())
                    .then(data => {
                        if (!data.success) return;
                        unreadBubble.textContent = data.count > 0 ? data.count : '0';
                        unreadBubble.classList.toggle('active', data.count > 0);
                        unreadBubble.dataset.hasUnread = data.count > 0 ? '1' : '0';
                        if (data.count > 0) {
                            renderUnreadPopup(data.users);
                        } else {
                            renderUnreadPopup([]);
                        }
                    })
                    .catch(err => console.error('Unread notifications error:', err));
            }

            if (unreadBubble) {
                unreadBubble.addEventListener('click', () => {
                    if (!unreadPopup) return;
                    unreadPopup.style.display = unreadPopup.style.display === 'flex' ? 'none' : 'flex';
                });
            }

            const closeUnreadPopupBtn = document.querySelector('.close-unread-popup');
            if (closeUnreadPopupBtn) {
                closeUnreadPopupBtn.addEventListener('click', () => {
                    if (unreadPopup) unreadPopup.style.display = 'none';
                });
            }

            document.addEventListener('click', function(e) {
                if (unreadPopup && unreadBubble && !unreadPopup.contains(e.target) && e.target !== unreadBubble) {
                    unreadPopup.style.display = 'none';
                }
            });

            refreshUnreadNotifications();
            setInterval(refreshUnreadNotifications, 20000);

            // Gestion de la Lightbox Media
            document.addEventListener('click', function(e) {
                const modal = document.getElementById('mediaModal');
                const container = document.getElementById('modalMediaContainer');
                const commentsContainer = document.getElementById('modalCommentsContainer');
                if (!modal || !container || !commentsContainer) return;

                // Fermeture si clic sur la croix ou à l'extérieur de l'image
                if (e.target.classList.contains('modal-media') || e.target.classList.contains('close-media')) {
                    modal.style.display = 'none';
                    container.innerHTML = '';
                    commentsContainer.innerHTML = '';
                    return;
                }

                // Éléments à ignorer (boutons de lecture pour ne pas ouvrir la modal au clic sur Play)
                if (e.target.closest('.audio-play-button')) return;

                // Déterminer l'élément déclencheur (Image, Vidéo, Texte ou Audio)
                let trigger = null;
                if (e.target.tagName === 'IMG' || e.target.tagName === 'VIDEO') {
                    trigger = e.target;
                } else {
                    // Cherche si on a cliqué sur un bloc de texte (profil) ou un visualiseur audio
                    trigger = e.target.closest('.post-text') || e.target.closest('.audio-visualizer');
                    
                    // Gestion spécifique des posts purement textuels dans le flux d'actualité (balise h3)
                    if (!trigger && e.target.tagName === 'H3' && e.target.closest('.post-item')) {
                        trigger = e.target;
                    }
                }

                if (trigger) {
                    // On ignore les photos de profil
                    if (trigger.closest('.people') || trigger.closest('.user-mini') || trigger.classList.contains('profile-pic')) return;

                    // On cible uniquement les médias des publications, galerie ou messages
                    if (trigger.closest('.post-item') || trigger.closest('.post') || trigger.closest('.bulle') || trigger.closest('.comment-item')) {
                        container.innerHTML = '';
                        const clone = trigger.cloneNode(true);
                        
                        // On laisse le CSS de la modal gérer les dimensions
                        clone.style.width = '';
                        clone.style.height = '';

                        if (trigger.tagName === 'VIDEO') {
                            clone.controls = true;
                            clone.play();
                        }

                        // Si c'est un audio, on prépare sa ré-initialisation dans la modal
                        if (clone.classList.contains('audio-visualizer')) {
                            clone.classList.remove('initialized');
                            const oldAudio = clone.querySelector('audio');
                            if (oldAudio) oldAudio.remove();
                        }
                        
                        container.appendChild(clone);

                        // Charger les commentaires du post
                        const postItem = trigger.closest('.post-item') || trigger.closest('.post');
                        if (postItem) {
                            const postId = postItem.classList.contains('post-item') ? postItem.id.split('-')[1] : postItem.dataset.postId;
                            if (postId) {
                                fetch(`user_page.php?post_id=${postId}&ajax_comments=1&all=1`)
                                    .then(res => res.text())
                                    .then(html => {
                                        commentsContainer.innerHTML = `
                                            <h3 style="margin-bottom:15px; border-bottom:1px solid #eee; padding-bottom:10px; color:#1877f2;">Commentaires</h3>
                                            <div class="modal-comments-list" style="flex: 1; overflow-y: auto; margin-bottom: 10px;">${html || '<p style="color:#666; font-size:0.9rem;">Aucun commentaire.</p>'}</div>
                                            <form method="POST" action="user_page.php" enctype="multipart/form-data" class="comment-form" id="modalCommentForm">
                                                <input type="hidden" name="post_id" id="modalPostId" value="">
                                                <input class="wrotecomment" type="text" name="comment_content" placeholder="Ajouter un commentaire...">
                                                <div class="wrote" style="margin-top: 6px; flex-wrap: wrap; height: auto; gap: 4px; padding: 0;">
                                                    <button class="sendcomment" type="submit" name="submit_comment" style="flex: 1; min-width: 70px;">Envoyer</button>
                                                    <button type="button" class="sendMediaComment" style="flex: 0 1 40px; padding: 0;"><i class="fa-regular fa-image"></i></button>
                                                    <button type="button" class="micButtonComment" style="flex: 0 1 40px; padding: 0;"><i class="fa-solid fa-microphone"></i></button>
                                                    <input type="file" name="comment_media_file" class="comment-media-input" style="display:none;" accept="image/*,video/*,audio/*">
                                                    <input type="hidden" class="recordedCommentAudioData" name="recorded_comment_audio_data">
                                                </div>
                                                <span class="recordStatusComment" style="font-size: 0.8rem; color: #1877f2; margin-top: 4px;"></span>
                                                <div class="fileNameDisplayComment" style="display:none; font-size: 0.8rem; color: #1877f2; margin-top: 4px;"></div>
                                            </form>
                                        `;
                                        if (typeof initAudioVisualizers === 'function') initAudioVisualizers();

                                        // Met à jour l'ID du post pour le formulaire de la modal
                                        const modalPostIdInput = document.getElementById('modalPostId');
                                        if (modalPostIdInput) modalPostIdInput.value = postId;

                                        // Initialise les boutons de média pour le nouveau formulaire
                                        if (typeof initCommentForms === 'function') initCommentForms();

                                        // Attache le gestionnaire de soumission AJAX pour le formulaire de la modal (créé dynamiquement)
                                        const modalCommentFormDynamic = document.getElementById('modalCommentForm');
                                        if (modalCommentFormDynamic) {
                                            modalCommentFormDynamic.addEventListener('submit', function(e) {
                                                e.preventDefault();
                                                const formData = new FormData(this);
                                                // Inclure explicitement le flag submit_comment car FormData(form) n'inclut pas le bouton soumis
                                                formData.append('submit_comment', '1');
                                                const action = this.getAttribute('action') || 'user_page.php';
                                                const ajaxUrl = action + (action.includes('?') ? '&' : '?') + 'ajax_submit=1';

                                                const submitBtn = this.querySelector('.sendcomment');
                                                if (submitBtn) {
                                                    submitBtn.disabled = true;
                                                    submitBtn.textContent = 'Envoi...';
                                                }

                                                fetch(ajaxUrl, {
                                                    method: 'POST',
                                                    body: formData
                                                })
                                                .then(response => response.text())
                                                .then(html => {
                                                    const commentsList = document.querySelector('.modal-comments-list');
                                                    if (commentsList) {
                                                        commentsList.insertAdjacentHTML('beforeend', html);
                                                        commentsList.scrollTop = commentsList.scrollHeight;
                                                    }
                                                    this.reset();
                                                    const status = this.querySelector('.recordStatusComment');
                                                    const fileDisplay = this.querySelector('.fileNameDisplayComment');
                                                    if (status) status.textContent = '';
                                                    if (fileDisplay) fileDisplay.style.display = 'none';
                                                    if (typeof initAudioVisualizers === 'function') initAudioVisualizers();
                                                })
                                                .catch(error => console.error('Erreur lors de l\'envoi AJAX:', error))
                                                .finally(() => {
                                                    if (submitBtn) {
                                                        submitBtn.disabled = false;
                                                        submitBtn.textContent = 'Envoyer';
                                                    }
                                                });
                                            });
                                        }
                                    });
                            }
                        } else {
                            commentsContainer.innerHTML = '<p style="padding:20px; color:#666;">Commentaires indisponibles.</p>';
                        }

                        modal.style.display = 'flex';

                        // Re-initialisation du visualiseur pour le clone dans la modal
                        if (clone.classList.contains('audio-visualizer')) {
                            initAudioVisualizers();
                        }
                    }
                }
            });

            function blobToDataURL(blob) {
                return new Promise((resolve) => {
                    const reader = new FileReader();
                    reader.onloadend = () => resolve(reader.result);
                    reader.readAsDataURL(blob);
                });
            }

            window.openMediaFile = function(prefix) {
                const recordedAudioData = document.getElementById(prefix + '_recordedAudioData');
                const recordStatus = document.getElementById(prefix + '_recordStatus');
                const mediaInput = document.getElementById(prefix + '_mediaInput');
                if (recordedAudioData) recordedAudioData.value = '';
                if (recordStatus) recordStatus.textContent = '';
                if (mediaInput) mediaInput.click();
            };

            function setupMediaHandling(prefix) {
                const mediaInput = document.getElementById(prefix + '_mediaInput');
                const fileNameDisplay = document.getElementById(prefix + '_fileNameDisplay');
                const micButton = document.getElementById(prefix + '_micButton');
                const recordStatus = document.getElementById(prefix + '_recordStatus');
                const recordedAudioData = document.getElementById(prefix + '_recordedAudioData');
                const textarea = document.getElementById(prefix + '_contenu');

                if (!mediaInput || !micButton) return;

                mediaInput.addEventListener('change', function() {
                    if (recordedAudioData) recordedAudioData.value = '';
                    if (recordStatus) recordStatus.textContent = '';
                    if (this.files && this.files.length > 0) {
                        fileNameDisplay.innerHTML = '<i class="fa-solid fa-paperclip"></i> ' + this.files[0].name;
                        fileNameDisplay.style.display = 'block';
                        if(textarea) textarea.classList.add('with-file');
                    } else {
                        fileNameDisplay.textContent = '';
                        fileNameDisplay.style.display = 'none';
                        if(textarea) textarea.classList.remove('with-file');
                    }
                });

                let mediaRecorder = null;
                let audioChunks = [];

                micButton.addEventListener('click', async function() {
                    if (mediaRecorder && mediaRecorder.state === 'recording') {
                        mediaRecorder.stop();
                        return;
                    }
                    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                        recordStatus.textContent = 'Micro non supporté.';
                        return;
                    }
                    try {
                        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                        mediaRecorder = new MediaRecorder(stream);
                        audioChunks = [];
                        mediaRecorder.ondataavailable = e => audioChunks.push(e.data);
                        mediaRecorder.onstop = async () => {
                            const audioBlob = new Blob(audioChunks, { type: 'audio/webm' });
                            recordedAudioData.value = await blobToDataURL(audioBlob);
                            recordStatus.textContent = 'Enregistrement prêt.';
                            mediaInput.value = '';
                            fileNameDisplay.textContent = '';
                            fileNameDisplay.style.display = 'none';
                            if(textarea) textarea.classList.remove('with-file');
                            stream.getTracks().forEach(track => track.stop());
                            micButton.innerHTML = '<i class="fa-solid fa-microphone"></i>';
                        };
                        mediaRecorder.start();
                        micButton.innerHTML = '<i class="fa-solid fa-stop"></i>';
                        recordStatus.textContent = 'Enregistrement...';
                    } catch (err) {
                        recordStatus.textContent = 'Erreur micro.';
                    }
                });
            }

            // Initialisation pour le formulaire de Post et de Message
            setupMediaHandling('post');
            setupMediaHandling('msg');

            function initAudioVisualizers() {
                const visualizers = document.querySelectorAll('.audio-visualizer:not(.initialized)');
                visualizers.forEach((container) => {
                    container.classList.add('initialized');
                    const audioSrc = container.dataset.src;
                    const playButton = container.querySelector('.audio-play-button');
                    const statusLabel = container.querySelector('.audio-status');
                    const canvas = container.querySelector('canvas');
                    const audio = document.createElement('audio');
                    audio.src = audioSrc;
                    audio.preload = 'metadata';
                    audio.style.display = 'none';
                    container.appendChild(audio);

                    let audioCtx = null;
                    let analyser = null;
                    let dataArray = null;
                    let animationId = null;

                    function setupVisualizer() {
                        if (audioCtx) return;
                        audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                        analyser = audioCtx.createAnalyser();
                        analyser.fftSize = 256;
                        const source = audioCtx.createMediaElementSource(audio);
                        source.connect(analyser);
                        analyser.connect(audioCtx.destination);
                        dataArray = new Uint8Array(analyser.frequencyBinCount);
                    }

                    function draw() {
                        if (!analyser) return;
                        analyser.getByteTimeDomainData(dataArray);
                        const ctx = canvas.getContext('2d');
                        const width = canvas.clientWidth;
                        const height = canvas.clientHeight;
                        canvas.width = width * window.devicePixelRatio;
                        canvas.height = height * window.devicePixelRatio;
                        ctx.setTransform(window.devicePixelRatio, 0, 0, window.devicePixelRatio, 0, 0);
                        ctx.clearRect(0, 0, width, height);
                        ctx.fillStyle = '#1f1f1f';
                        ctx.fillRect(0, 0, width, height);
                        ctx.lineWidth = 2;
                        ctx.strokeStyle = '#4d9cff';
                        ctx.beginPath();
                        const sliceWidth = width / dataArray.length;
                        let x = 0;
                        for (let i = 0; i < dataArray.length; i++) {
                            const v = dataArray[i] / 128.0;
                            const y = (v * height) / 2;
                            if (i === 0) {
                                ctx.moveTo(x, y);
                            } else {
                                ctx.lineTo(x, y);
                            }
                            x += sliceWidth;
                        }
                        ctx.lineTo(width, height / 2);
                        ctx.stroke();
                        animationId = requestAnimationFrame(draw);
                    }

                    playButton.addEventListener('click', async () => {
                        if (audio.paused) {
                            setupVisualizer();
                            try {
                                await audioCtx.resume();
                            } catch (err) {
                                // ignore
                            }
                            audio.play();
                            playButton.textContent = '❚❚ Pause';
                            statusLabel.textContent = 'Lecture en cours...';
                            if (!animationId) draw();
                        } else {
                            audio.pause();
                            playButton.textContent = '▶ Play';
                            statusLabel.textContent = 'En pause';
                            if (animationId) {
                                cancelAnimationFrame(animationId);
                                animationId = null;
                            }
                        }
                    });

                    audio.addEventListener('ended', () => {
                        playButton.textContent = '▶ Play';
                        statusLabel.textContent = 'Lecture terminée';
                        if (animationId) {
                            cancelAnimationFrame(animationId);
                            animationId = null;
                        }
                    });

                    audio.addEventListener('loadedmetadata', () => {
                        const duration = Math.round(audio.duration);
                        statusLabel.textContent = 'Durée : ' + duration + 's';
                    });
                });
            }

            function initCommentForms() {
                document.querySelectorAll('.comment-form:not(.initialized)').forEach(form => {
                    form.classList.add('initialized');
                const mediaInput = form.querySelector('.comment-media-input');
                const mediaBtn = form.querySelector('.sendMediaComment');
                const micBtn = form.querySelector('.micButtonComment');
                const recordStatus = form.querySelector('.recordStatusComment');
                const audioDataInput = form.querySelector('.recordedCommentAudioData');
                const fileNameDisplay = form.querySelector('.fileNameDisplayComment');

                let commentRecorder = null;
                let commentChunks = [];

                mediaBtn.addEventListener('click', () => {
                    audioDataInput.value = '';
                    recordStatus.textContent = '';
                    mediaInput.click();
                });

                mediaInput.addEventListener('change', function() {
                    if (this.files && this.files.length > 0) {
                        fileNameDisplay.innerHTML = '<i class="fa-solid fa-paperclip"></i> ' + this.files[0].name;
                        fileNameDisplay.style.display = 'block';
                    } else {
                        fileNameDisplay.style.display = 'none';
                    }
                });

                micBtn.addEventListener('click', async () => {
                    if (commentRecorder && commentRecorder.state === 'recording') {
                        commentRecorder.stop();
                        return;
                    }
                    try {
                        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                        commentRecorder = new MediaRecorder(stream);
                        commentChunks = [];
                        commentRecorder.ondataavailable = e => commentChunks.push(e.data);
                        commentRecorder.onstop = async () => {
                            const blob = new Blob(commentChunks, { type: 'audio/webm' });
                            audioDataInput.value = await blobToDataURL(blob);
                            recordStatus.textContent = 'Audio prêt.';
                            micBtn.innerHTML = '<i class="fa-solid fa-microphone"></i>';
                            stream.getTracks().forEach(t => t.stop());
                        };
                        commentRecorder.start();
                        micBtn.innerHTML = '<i class="fa-solid fa-stop"></i>';
                        recordStatus.textContent = 'Enregistrement...';
                    } catch (err) {
                        recordStatus.textContent = 'Erreur micro.';
                    }
                });
                });
            }

            // Auto-scroll vers le bas de la conversation
            window.addEventListener('load', function() {
                const conv = document.querySelector('.conv');
                if (conv) {
                    conv.scrollTop = conv.scrollHeight;
                }
                initAudioVisualizers();
                initCommentForms();

                // Gestion de la soumission AJAX pour le formulaire de commentaire de la modal
                const modalCommentForm = document.getElementById('modalCommentForm');
                if (modalCommentForm) {
                    modalCommentForm.addEventListener('submit', function(e) {
                        e.preventDefault();
                        
                        const formData = new FormData(this);
                        // Inclure explicitement le flag submit_comment car FormData(form) n'inclut pas le bouton soumis
                        formData.append('submit_comment', '1');
                        const action = this.getAttribute('action');
                        // On ajoute un paramètre pour que PHP sache que c'est une soumission AJAX
                        const ajaxUrl = action + (action.includes('?') ? '&' : '?') + 'ajax_submit=1';

                        const submitBtn = this.querySelector('.sendcomment');
                        submitBtn.disabled = true;
                        submitBtn.textContent = 'Envoi...';

                        fetch(ajaxUrl, {
                            method: 'POST',
                            body: formData
                        })
                        .then(response => response.text())
                        .then(html => {
                            const commentsList = document.querySelector('.modal-comments-list');
                            if (commentsList) {
                                commentsList.insertAdjacentHTML('beforeend', html);
                                commentsList.scrollTop = commentsList.scrollHeight; // Scroll vers le nouveau commentaire
                            }
                            this.reset();
                            // Nettoyage des indicateurs de fichiers/audio
                            const status = this.querySelector('.recordStatusComment');
                            const fileDisplay = this.querySelector('.fileNameDisplayComment');
                            if (status) status.textContent = '';
                            if (fileDisplay) fileDisplay.style.display = 'none';

                            if (typeof initAudioVisualizers === 'function') initAudioVisualizers();
                        })
                        .catch(error => console.error('Erreur lors de l\'envoi AJAX:', error))
                        .finally(() => {
                            submitBtn.disabled = false;
                            submitBtn.textContent = 'Envoyer';
                        });
                    });
                }
            }); 