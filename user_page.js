 const inputField = document.getElementById('username_destinataire');
            const resultsContainer = document.getElementById('searchResults');

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
                            data.forEach(username => {
                                const item = document.createElement('div');
                                item.className = 'search-result-item';
                                item.textContent = username;
                                item.addEventListener('click', function() {
                                    window.location.href = 'user_page.php?with=' + encodeURIComponent(username);
                                });
                                resultsContainer.appendChild(item);
                            });
                            resultsContainer.classList.add('active');
                        } else {
                            resultsContainer.classList.remove('active');
                        }
                    });
            });

            document.addEventListener('click', function(e) {
                if (e.target !== inputField && e.target !== resultsContainer) {
                    resultsContainer.classList.remove('active');
                }
            });

            // Afficher le nom du fichier sélectionné
            const mediaInput = document.getElementById('mediaInput');
            const fileNameDisplay = document.getElementById('fileNameDisplay');
            const micButton = document.getElementById('micButton');
            const recordStatus = document.getElementById('recordStatus');
            const recordedAudioData = document.getElementById('recordedAudioData');
            let mediaRecorder = null;
            let mediaStream = null;
            let audioChunks = [];

            function openMediaFile() {
                recordedAudioData.value = '';
                mediaInput.click();
            }

            mediaInput.addEventListener('change', function() {
                const textarea = document.getElementById('contenu');
                recordedAudioData.value = '';
                recordStatus.textContent = '';
                if (this.files && this.files.length > 0) {
                    fileNameDisplay.innerHTML = '<i class="fa-solid fa-paperclip"></i> ' + this.files[0].name;
                    fileNameDisplay.style.display = 'block';
                    textarea.classList.add('with-file');
                } else {
                    fileNameDisplay.textContent = '';
                    fileNameDisplay.style.display = 'none';
                    textarea.classList.remove('with-file');
                }
            });

            function blobToDataURL(blob) {
                return new Promise((resolve) => {
                    const reader = new FileReader();
                    reader.onloadend = () => resolve(reader.result);
                    reader.readAsDataURL(blob);
                });
            }

            micButton.addEventListener('click', async function() {
                if (mediaRecorder && mediaRecorder.state === 'recording') {
                    mediaRecorder.stop();
                    return;
                }

                if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                    recordStatus.textContent = 'Votre navigateur ne supporte pas le micro.';
                    return;
                }

                try {
                    mediaStream = await navigator.mediaDevices.getUserMedia({ audio: true });
                    mediaRecorder = new MediaRecorder(mediaStream);
                    audioChunks = [];

                    mediaRecorder.addEventListener('dataavailable', function(event) {
                        if (event.data.size > 0) {
                            audioChunks.push(event.data);
                        }
                    });

                    mediaRecorder.addEventListener('stop', async function() {
                        const audioBlob = new Blob(audioChunks, { type: 'audio/webm' });
                        const audioDataURL = await blobToDataURL(audioBlob);
                        recordedAudioData.value = audioDataURL;
                        recordStatus.textContent = 'Enregistrement prêt. Cliquez sur Envoyer.';
                        mediaInput.value = '';
                        fileNameDisplay.textContent = '';
                        fileNameDisplay.style.display = 'none';
                        document.getElementById('contenu').classList.remove('with-file');
                        if (mediaStream) {
                            mediaStream.getTracks().forEach(track => track.stop());
                            mediaStream = null;
                        }
                        micButton.innerHTML = '<i class="fa-solid fa-microphone"></i>';
                    });

                    mediaRecorder.start();
                    micButton.innerHTML = '<i class="fa-solid fa-stop"></i>';
                    recordStatus.textContent = 'Enregistrement en cours...';
                } catch (err) {
                    recordStatus.textContent = 'Impossible d\'accéder au micro.';
                }
            });

            function initAudioVisualizers() {
                const visualizers = document.querySelectorAll('.audio-visualizer');
                visualizers.forEach((container) => {
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

            // Auto-scroll vers le bas de la conversation
            window.addEventListener('load', function() {
                const conv = document.querySelector('.conv');
                if (conv) {
                    conv.scrollTop = conv.scrollHeight;
                }
                initAudioVisualizers();
            }); 