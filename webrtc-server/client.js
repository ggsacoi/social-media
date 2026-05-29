/**
 * WebRTC Client for livestreaming
 * Connects to Socket.io signaling server and manages peer connections
 */

class WebRTCClient {
  constructor(signalingServerUrl) {
    this.signalingServerUrl = signalingServerUrl || `http://${window.location.hostname}:3000`;
    this.socket = null;
    this.peerConnections = new Map();
    this.localStream = null;
    this.config = {
      iceServers: [
        { urls: ['stun:stun.l.google.com:19302'] },
        { urls: ['stun:stun1.l.google.com:19302'] }
      ]
    };
    this.isConnected = false;
  }

  /**
   * Initialize Socket.io connection
   */
  async connect() {
    return new Promise((resolve, reject) => {
      if (!window.io) {
        reject(new Error('Socket.io library not loaded'));
        return;
      }

      this.socket = window.io(this.signalingServerUrl, {
        reconnection: true,
        reconnectionDelay: 1000,
        reconnectionDelayMax: 5000,
        reconnectionAttempts: 5
      });

      this.socket.on('connect', () => {
        console.log('✓ Connected to signaling server');
        this.isConnected = true;
        resolve();
      });

      this.socket.on('connect_error', (error) => {
        console.error('Connection error:', error);
        this.isConnected = false;
      });

      this.socket.on('disconnect', () => {
        console.log('Disconnected from signaling server');
        this.isConnected = false;
      });

      // Handle incoming offers (viewer receiving stream)
      this.socket.on('offer', async (data) => {
        const { offer, from } = data;
        console.log('📨 Received offer from', from);
        await this._handleOffer(offer, from);
      });

      // Handle incoming answers (broadcaster receiving viewer connection)
      this.socket.on('answer', async (data) => {
        const { answer, from } = data;
        console.log('📨 Received answer from', from);
        await this._handleAnswer(answer, from);
      });

      // Handle ICE candidates
      this.socket.on('ice-candidate', async (data) => {
        const { candidate, from } = data;
        if (candidate) {
          await this._addIceCandidate(candidate, from);
        }
      });

      this.socket.on('viewer-joined', (data) => {
        console.log('👁️ New viewer joined:', data.viewerId);
      });

      setTimeout(() => {
        reject(new Error('Connection timeout'));
      }, 5000);
    });
  }

  /**
   * Start broadcasting (broadcaster mode)
   */
  async startBroadcaster(userId, username) {
    try {
      // Get media stream
      this.localStream = await navigator.mediaDevices.getUserMedia({
        video: { width: { ideal: 1280 }, height: { ideal: 720 } },
        audio: true
      });

      // Notify server that we're broadcasting
      this.socket.emit('broadcaster-join', { userId, username });

      console.log('✓ Broadcasting started');
      return this.localStream;
    } catch (error) {
      console.error('Error accessing media devices:', error);
      throw error;
    }
  }

  /**
   * Start viewing a livestream (viewer mode)
   */
  async startViewer(userId, broadcasterUserId, videoElement) {
    try {
      this.socket.emit('viewer-join', { userId, viewingUserId: broadcasterUserId });

      // Get microphone (optional for viewers)
      try {
        this.localStream = await navigator.mediaDevices.getUserMedia({ audio: true });
      } catch (e) {
        console.log('Viewer without microphone');
      }

      // Wait for offer from broadcaster
      console.log('⏳ Waiting for stream from broadcaster...');
      return new Promise((resolve) => {
        const timeout = setTimeout(() => {
          console.warn('⏱️ Timeout waiting for stream');
          resolve(null);
        }, 10000);

        const originalOn = this.socket.on.bind(this.socket);
        this.socket.on = function(event, handler) {
          if (event === 'offer' && handler) {
            const wrappedHandler = async function(data) {
              clearTimeout(timeout);
              await handler(data);
            };
            return originalOn(event, wrappedHandler);
          }
          return originalOn(event, handler);
        };
      });
    } catch (error) {
      console.error('Error starting viewer:', error);
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

    console.log('✓ Stream stopped');
  }

  /**
   * Create offer and send to peer
   */
  async _createAndSendOffer(peerId) {
    const pc = this._getOrCreatePeerConnection(peerId);
    
    if (this.localStream) {
      this.localStream.getTracks().forEach(track => {
        pc.addTrack(track, this.localStream);
      });
    }

    const offer = await pc.createOffer();
    await pc.setLocalDescription(offer);
    
    this.socket.emit('offer', { to: peerId, offer });
    console.log('📤 Sent offer to', peerId);
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
    console.log('📤 Sent answer to', peerId);
  }

  /**
   * Handle incoming answer
   */
  async _handleAnswer(answer, peerId) {
    const pc = this.peerConnections.get(peerId);
    if (pc) {
      await pc.setRemoteDescription(new RTCSessionDescription(answer));
      console.log('✓ Answer received from', peerId);
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
        console.error('Error adding ICE candidate:', error);
      }
    }
  }

  /**
   * Get or create peer connection
   */
  _getOrCreatePeerConnection(peerId) {
    if (!this.peerConnections.has(peerId)) {
      const pc = new RTCPeerConnection({ iceServers: this.config.iceServers });

      // Handle ICE candidates
      pc.onicecandidate = (event) => {
        if (event.candidate) {
          this.socket.emit('ice-candidate', {
            to: peerId,
            candidate: event.candidate
          });
        }
      };

      // Handle remote stream
      pc.ontrack = (event) => {
        console.log('📹 Remote track received');
        const videoElement = document.getElementById('liveVideo');
        if (videoElement && event.streams[0]) {
          videoElement.srcObject = event.streams[0];
        }
      };

      pc.onconnectionstatechange = () => {
        console.log('Connection state:', pc.connectionState);
      };

      this.peerConnections.set(peerId, pc);
    }
    return this.peerConnections.get(peerId);
  }
}

// Global instance
window.WebRTCClient = null;

// Initialize on window load
document.addEventListener('DOMContentLoaded', () => {
  window.WebRTCClient = new WebRTCClient();
  console.log('✓ WebRTC Client initialized');
});
