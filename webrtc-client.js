/**
 * WebRTC Client for livestreaming
 * Connects to Socket.io signaling server and manages peer connections
 */

class WebRTCClient {
  constructor(signalingServerUrl) {
    const defaultProtocol = window.location.protocol === 'https:' ? 'https:' : 'http:';
    this.signalingServerUrl = signalingServerUrl || `${defaultProtocol}//${window.location.hostname}:3000`;
    this.socket = null;
    this.peerConnections = new Map();
    this.localStream = null;
    this.remoteStream = null;
    this.remoteVideoElement = null;
    this._viewerTimeoutId = null;
    this._viewerResolve = null;
    this.configLoaded = false;
    this.config = {
      iceServers: [
        { urls: ['stun:stun.l.google.com:19302'] },
        { urls: ['stun:stun1.l.google.com:19302'] }
      ]
    };
    this.isConnected = false;
  }

  async _loadClientConfig() {
    if (this.configLoaded) return;
    this.configLoaded = true;

    try {
      const response = await fetch('./config.webrtc.json', { cache: 'no-store' });
      if (!response.ok) {
        console.warn('[WebRTC] Config file not found, using defaults');
        return;
      }
      const config = await response.json();
      if (config.signalingServerUrl) {
        this.signalingServerUrl = config.signalingServerUrl;
      }
      if (Array.isArray(config.stunServers) && config.stunServers.length > 0) {
        this.config.iceServers = config.stunServers.map(url => ({ urls: url }));
      }
      console.log('[WebRTC] ✓ Loaded config.webrtc.json', config);
    } catch (error) {
      console.warn('[WebRTC] Unable to load config.webrtc.json, using defaults', error);
    }
  }


  /**
   * Initialize Socket.io connection
   */
  /**
   * Initialize Socket.io connection
   */
  async connect() {
    await this._loadClientConfig();

    return new Promise((resolve, reject) => {
      const url = this.signalingServerUrl || `${window.location.protocol}//${window.location.hostname}:3000`;
      this.signalingServerUrl = url;

      if (!window.io) {
        console.log('[WebRTC] Socket.io not found in window, attempting dynamic load from', url);
        const script = document.createElement('script');
        
        try {
          const baseOrigin = new URL(url).origin;
          script.src = `${baseOrigin}/socket.io/socket.io.js`;
        } catch (e) {
          script.src = `${window.location.protocol}//${window.location.hostname}:3000/socket.io/socket.io.js`;
        }

        script.onload = () => {
          console.log('[WebRTC] ✓ Socket.io loaded dynamically from', script.src);
          if (window.io) {
            this._establishSocketConnection(resolve, reject);
          } else {
            reject(new Error('Socket.io script loaded, but window.io is still undefined.'));
          }
        };
        script.onerror = () => {
          reject(new Error(`Failed to load Socket.io library from ${script.src}. Make sure the signaling server is running on port 3000.`));
        };
        document.head.appendChild(script);
      } else {
        this._establishSocketConnection(resolve, reject);
      }
    });
  }

  _establishSocketConnection(resolve, reject) {
    const url = this.signalingServerUrl;
    console.log('[WebRTC] Attempting to connect to signaling server at', url);

    this.socket = window.io(url, {
      transports: ['websocket', 'polling'],
      path: '/socket.io',
      reconnection: true,
      reconnectionDelay: 1000,
      reconnectionDelayMax: 5000,
      reconnectionAttempts: 5
    });

    let connected = false;
    const timeout = setTimeout(() => {
      if (!connected) {
        connected = true;
        reject(new Error('Connection timeout to signaling server'));
      }
    }, 5000);

    this.socket.on('connect', () => {
      console.log('[WebRTC] ✓ Connected to signaling server');
      this.isConnected = true;
      connected = true;
      clearTimeout(timeout);
      resolve();
    });

    this.socket.on('connect_error', (error) => {
      console.error('[WebRTC] Connection error:', error);
      this.isConnected = false;
      if (!connected) {
        connected = true;
        clearTimeout(timeout);
        reject(error);
      }
    });

    this.socket.on('reconnect_error', (error) => {
      console.warn('[WebRTC] Reconnect error:', error);
    });

    this.socket.on('reconnect_failed', () => {
      console.warn('[WebRTC] Reconnect failed');
    });

    this.socket.on('disconnect', () => {
      console.log('[WebRTC] Disconnected from signaling server');
      this.isConnected = false;
    });

    // Handle incoming offers (viewer receiving stream)
    this.socket.on('offer', async (data) => {
      const { offer, fromUserId, fromSocketId, from } = data;
      const peerId = fromUserId ?? fromSocketId ?? from;
      console.log('[WebRTC] 📨 Received offer from', peerId, '(userId=', fromUserId, ', socketId=', fromSocketId, ')');
      await this._handleOffer(offer, peerId);
    });

    // Handle incoming answers (broadcaster receiving viewer connection)
    this.socket.on('answer', async (data) => {
      const { answer, fromUserId, fromSocketId, from } = data;
      const peerId = fromUserId ?? fromSocketId ?? from;
      console.log('[WebRTC] 📨 Received answer from', peerId, '(userId=', fromUserId, ', socketId=', fromSocketId, ')');
      await this._handleAnswer(answer, peerId);
    });

    // Handle ICE candidates
    this.socket.on('ice-candidate', async (data) => {
      const { candidate, fromUserId, fromSocketId, from } = data;
      const peerId = fromUserId ?? fromSocketId ?? from;
      if (candidate) {
        await this._addIceCandidate(candidate, peerId);
      }
    });

    this.socket.on('viewer-joined', (data) => {
      console.log('[WebRTC] 👁️ New viewer joined:', data.viewerId);
    });
  }

  /**
   * Start broadcasting (broadcaster mode)
   */
  async startBroadcaster(userId, username, existingStream = null) {
    try {
      if (!this.isConnected) {
        await this.connect();
      }

      if (existingStream) {
        this.localStream = existingStream;
      } else {
        // Get media stream
        this.localStream = await navigator.mediaDevices.getUserMedia({
          video: { width: { ideal: 1280 }, height: { ideal: 720 } },
          audio: true
        });
      }

      console.log('[HLS-P2P] Starting HLS broadcaster...');
      
      // Setup MediaRecorder
      let mimeType = 'video/mp4; codecs="avc1.42E01E, mp4a.40.2"';
      if (!MediaRecorder.isTypeSupported(mimeType)) {
        console.warn('[HLS-P2P] Specified MP4 codecs not supported, trying general video/mp4');
        mimeType = 'video/mp4';
      }
      if (!MediaRecorder.isTypeSupported(mimeType)) {
        console.warn('[HLS-P2P] video/mp4 not supported, falling back to video/webm; codecs=h264');
        mimeType = 'video/webm; codecs=h264';
      }
      if (!MediaRecorder.isTypeSupported(mimeType)) {
        console.warn('[HLS-P2P] Fallback to video/webm');
        mimeType = 'video/webm';
      }

      console.log('[HLS-P2P] Using mimeType for recording:', mimeType);

      this.mediaRecorder = new MediaRecorder(this.localStream, {
        mimeType: mimeType,
        videoBitsPerSecond: 1200000 // 1.2 Mbps for good quality and reasonable size
      });

      let seq = 0;
      this.mediaRecorder.ondataavailable = async (event) => {
        if (event.data && event.data.size > 0) {
          const chunkSeq = seq++;
          console.log(`[HLS-P2P] Sending chunk ${chunkSeq}, size: ${event.data.size} bytes`);
          try {
            const arrayBuffer = await event.data.arrayBuffer();
            this.socket.emit('hls-chunk', {
              userId,
              username,
              seq: chunkSeq,
              data: arrayBuffer
            });
          } catch (err) {
            console.error('[HLS-P2P] Error sending HLS chunk:', err);
          }
        }
      };

      // Start recording with 2-second segments
      this.mediaRecorder.start(2000);

      // Notify server that we're broadcasting
      this.socket.emit('broadcaster-join', { userId, username });

      console.log('[HLS-P2P] ✓ Broadcasting started');
      return this.localStream;
    } catch (error) {
      console.error('[HLS-P2P] Error starting broadcaster:', error);
      throw error;
    }
  }

  /**
   * Start viewing a livestream (viewer mode)
   */
  async startViewer(userId, broadcasterUserId, videoElement) {
    const streamUrl = `live/${broadcasterUserId}/playlist.m3u8`;
    console.log('[HLS] Starting viewer for stream:', streamUrl);
    this.remoteVideoElement = videoElement || document.getElementById('liveVideo');

    return new Promise((resolve, reject) => {
      if (this.remoteVideoElement.canPlayType('application/vnd.apple.mpegurl')) {
        // Native HLS support (Safari)
        this.remoteVideoElement.src = streamUrl;
        this.remoteVideoElement.addEventListener('loadedmetadata', () => {
          this.remoteVideoElement.play().catch(e => console.warn('Play error:', e));
          resolve(true);
        }, { once: true });
        this.remoteVideoElement.addEventListener('error', (err) => {
          reject(err);
        }, { once: true });
      } else if (typeof window.Hls !== 'undefined') {
        // Standard HLS.js
        console.log('[HLS] Initializing standard Hls.js player...');
        const hls = new window.Hls({
          liveSyncPosition: 1,
          liveMaxLatencyDuration: 4
        });

        hls.on(window.Hls.Events.MANIFEST_PARSED, () => {
          console.log('[HLS] Manifest parsed, playing...');
          this.remoteVideoElement.play().catch(e => console.warn('Play error:', e));
          resolve(true);
        });

        hls.on(window.Hls.Events.ERROR, (event, data) => {
          console.error('[HLS] Hls error:', data);
          if (data.fatal) {
            switch (data.type) {
              case window.Hls.ErrorTypes.NETWORK_ERROR:
                console.log('[HLS] Network error, trying to recover...');
                hls.startLoad();
                break;
              case window.Hls.ErrorTypes.MEDIA_ERROR:
                console.log('[HLS] Media error, trying to recover...');
                hls.recoverMediaError();
                break;
              default:
                console.error('[HLS] Unrecoverable HLS error');
                break;
            }
          }
        });

        hls.loadSource(streamUrl);
        hls.attachMedia(this.remoteVideoElement);
        this.hlsInstance = hls;
      } else {
        reject(new Error('HLS is not supported on this browser and Hls.js library is missing.'));
      }
    });
  }

  /**
   * Stop broadcasting/viewing
   */
  async stop() {
    console.log('[HLS-P2P] Stopping stream...');
    if (this.mediaRecorder && this.mediaRecorder.state !== 'inactive') {
      try {
        this.mediaRecorder.stop();
      } catch (e) {
        console.warn('Error stopping mediaRecorder:', e);
      }
      this.mediaRecorder = null;
    }

    if (this.localStream) {
      this.localStream.getTracks().forEach(track => {
        try {
          track.stop();
        } catch (e) {
          console.warn('Error stopping track:', e);
        }
      });
      this.localStream = null;
    }

    if (this.hlsInstance) {
      try {
        this.hlsInstance.destroy();
      } catch (e) {
        console.warn('Error destroying HLS instance:', e);
      }
      this.hlsInstance = null;
    }

    if (this.socket && this.socket.connected) {
      try {
        this.socket.emit('broadcaster-stop');
      } catch (e) {
        console.warn('Error emitting broadcaster-stop:', e);
      }
    }

    console.log('[HLS-P2P] ✓ Stream stopped');
  }
}

// Global instance
window.WebRTCClient = null;

// Initialize on window load or script load
function initWebRTCClient() {
  if (!window.WebRTCClient) {
    window.WebRTCClient = new WebRTCClient();
    console.log('[WebRTC] ✓ Client initialized');
  }
}

// Try to initialize when this script loads
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initWebRTCClient);
} else {
  initWebRTCClient();
}
