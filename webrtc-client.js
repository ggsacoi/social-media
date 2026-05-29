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
  async connect() {
    await this._loadClientConfig();

    return new Promise((resolve, reject) => {
      if (!window.io) {
        reject(new Error('Socket.io library not loaded. Include <script src="http://localhost:3000/socket.io/socket.io.js"></script>'));
        return;
      }

      const url = this.signalingServerUrl || `${window.location.protocol}//${window.location.hostname}:3000`;
      console.log('[WebRTC] Attempting to connect to signaling server at', url);
      this.signalingServerUrl = url;

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
          reject(new Error('Connection timeout'));
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

      // Notify server that we're broadcasting
      this.socket.emit('broadcaster-join', { userId, username });

      console.log('[WebRTC] ✓ Broadcasting started');
      return this.localStream;
    } catch (error) {
      console.error('[WebRTC] Error accessing media devices:', error);
      throw error;
    }
  }

  /**
   * Start viewing a livestream (viewer mode)
   */
  async startViewer(userId, broadcasterUserId, videoElement) {
    try {
      if (!this.isConnected) {
        await this.connect();
      }

      this.remoteVideoElement = videoElement || document.getElementById('liveVideo');
      console.log('[WebRTC] 👁️ Viewer joining signaling server with userId=', userId, 'viewingUserId=', broadcasterUserId);
      this.socket.emit('viewer-join', { userId, viewingUserId: broadcasterUserId });

      // Create and send offer to broadcaster
      await this._createAndSendOffer(broadcasterUserId);

      console.log('[WebRTC] ⏳ Waiting for stream from broadcaster...');
      return new Promise((resolve) => {
        const timeout = setTimeout(() => {
          console.warn('[WebRTC] ⏱️ Timeout waiting for stream');
          this._viewerTimeoutId = null;
          this._viewerResolve = null;
          resolve(null);
        }, 10000);

        // Store reference to clear timeout and resolve when stream arrives
        this._viewerTimeoutId = timeout;
        this._viewerResolve = resolve;
      });
    } catch (error) {
      console.error('[WebRTC] Error starting viewer:', error);
      throw error;
    }
  }

  /**
   * Stop broadcasting/viewing
   */
  async stop() {
    if (this.localStream) {
      this.localStream.getTracks().forEach(track => track.stop());
      this.localStream = null;
    }

    // Close all peer connections
    for (const pc of this.peerConnections.values()) {
      pc.close();
    }
    this.peerConnections.clear();

    console.log('[WebRTC] ✓ Stream stopped');
  }

  /**
   * Create offer and send to peer
   */
  async _createAndSendOffer(peerId) {
    const pc = this._getOrCreatePeerConnection(peerId);
    console.log('[WebRTC] 🔧 Creating offer for peer', peerId, 'localStream=', this.localStream ? 'yes' : 'no');
    
    let hasAudioTrack = false;
    let hasVideoTrack = false;

    if (this.localStream) {
      this.localStream.getTracks().forEach(track => {
        console.log('[WebRTC] 🔧 Adding track to offer pc:', track.kind);
        pc.addTrack(track, this.localStream);
        if (track.kind === 'audio') hasAudioTrack = true;
        if (track.kind === 'video') hasVideoTrack = true;
      });
    }

    if (!hasVideoTrack) {
      console.log('[WebRTC] 🔧 Adding recvonly video transceiver');
      pc.addTransceiver('video', { direction: 'recvonly' });
    }
    if (!hasAudioTrack) {
      console.log('[WebRTC] 🔧 Adding recvonly audio transceiver');
      pc.addTransceiver('audio', { direction: 'recvonly' });
    }

    const offer = await pc.createOffer();
    await pc.setLocalDescription(offer);
    
    this.socket.emit('offer', { to: peerId, offer });
    console.log('[WebRTC] 📤 Sent offer to', peerId);
  }

  /**
   * Handle incoming offer
   */
  async _handleOffer(offer, peerId) {
    const pc = this._getOrCreatePeerConnection(peerId);

    if (this.localStream) {
      this.localStream.getTracks().forEach(track => {
        pc.addTrack(track, this.localStream);
      });
    }

    await pc.setRemoteDescription(new RTCSessionDescription(offer));
    const answer = await pc.createAnswer();
    await pc.setLocalDescription(answer);
    
    this.socket.emit('answer', { to: peerId, answer });
    console.log('[WebRTC] 📤 Sent answer to', peerId);
  }

  /**
   * Handle incoming answer
   */
  async _handleAnswer(answer, peerId) {
    const pc = this.peerConnections.get(peerId);
    if (pc) {
      console.log('[WebRTC] 🔧 Handling answer from', peerId, 'type=', answer.type);
      await pc.setRemoteDescription(new RTCSessionDescription(answer));
      console.log('[WebRTC] ✓ Answer received from', peerId);
    } else {
      console.warn('[WebRTC] ⚠️ Answer received for unknown peer', peerId);
    }
  }

  /**
   * Add ICE candidate
   */
  async _addIceCandidate(candidate, peerId) {
    const pc = this.peerConnections.get(peerId);
    if (pc && candidate) {
      try {
        await pc.addIceCandidate(new RTCIceCandidate(candidate));
      } catch (error) {
        console.error('[WebRTC] Error adding ICE candidate:', error);
      }
    } else {
      console.warn('[WebRTC] ⚠️ ICE candidate for unknown peer', peerId);
    }
  }

  /**
   * Get or create peer connection
   */
  _getOrCreatePeerConnection(peerId) {
    if (!this.peerConnections.has(peerId)) {
      console.log('[WebRTC] 🔧 Creating RTCPeerConnection for', peerId);
      const pc = new RTCPeerConnection({ iceServers: this.config.iceServers });

      // Handle ICE candidates
      pc.onicecandidate = (event) => {
        if (event.candidate) {
          console.log('[WebRTC] ❄️ Sending ICE candidate to', peerId);
          this.socket.emit('ice-candidate', {
            to: peerId,
            candidate: event.candidate
          });
        }
      };

      // Handle remote stream
      pc.ontrack = (event) => {
        console.log('[WebRTC] 📹 Remote track received');
        this.remoteStream = event.streams[0];
        const videoElement = this.remoteVideoElement || document.getElementById('liveVideo');
        if (videoElement && event.streams[0]) {
          videoElement.srcObject = event.streams[0];
          videoElement.play().catch(e => console.error('[WebRTC] Play error:', e));
        }
        // Clear viewer timeout when stream arrives
        if (this._viewerTimeoutId) {
          clearTimeout(this._viewerTimeoutId);
          this._viewerTimeoutId = null;
        }
        if (this._viewerResolve) {
          this._viewerResolve(this.remoteStream);
          this._viewerResolve = null;
        }
      };

      pc.onconnectionstatechange = () => {
        console.log('[WebRTC] Connection state:', pc.connectionState);
      };

      this.peerConnections.set(peerId, pc);
    }
    return this.peerConnections.get(peerId);
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
