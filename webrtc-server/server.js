const express = require('express');
const app = express();
const http = require('http');
const socketIO = require('socket.io');
const cors = require('cors');
const mysql = require('mysql2/promise');
const fs = require('fs');
const path = require('path');
const jpeg = require('jpeg-js');
const dns = require('dns');

if (dns.setDefaultResultOrder) {
  dns.setDefaultResultOrder('ipv4first');
}

process.env.NODE_TLS_REJECT_UNAUTHORIZED = '0';

let tf;
let nsfwjs;
let nsfwModel = null;
let nsfwModelLoading = false;

// Tentative de chargement de TensorFlow et NSFWJS
try {
  tf = require('@tensorflow/tfjs-node');
  console.log('✅ Loaded @tensorflow/tfjs-node');
} catch (nodeErr) {
  try {
    tf = require('@tensorflow/tfjs');
    console.log('⚠️ Failed to load @tensorflow/tfjs-node, fallback to @tensorflow/tfjs');
  } catch (err) {
    console.error('❌ Could not load @tensorflow/tfjs-node or @tensorflow/tfjs:', err.message);
  }
}

try {
  nsfwjs = require('nsfwjs');
  console.log('✅ Loaded nsfwjs');
} catch (err) {
  console.error('❌ Could not load nsfwjs:', err.message);
}

const server = http.createServer(app);
const io = socketIO(server, {
  cors: {
    origin: "*",
    methods: ["GET", "POST"]
  }
});

const PORT = process.env.PORT || 3000;
const dbConfig = {
  host: process.env.DB_HOST || 'localhost',
  user: process.env.DB_USER || 'root',
  password: process.env.DB_PASSWORD || '',
  database: process.env.DB_NAME || 'login-portfolio',
  waitForConnections: true,
  connectionLimit: 10,
  queueLimit: 0
};
let dbPool = null;

async function initDb() {
  try {
    dbPool = mysql.createPool(dbConfig);
    await dbPool.query('SELECT 1');
    console.log('✅ MySQL pool connected');
  } catch (error) {
    console.warn('⚠️ Impossible de se connecter à MySQL depuis le serveur Node:', error.message);
    dbPool = null;
  }
}

async function closeActiveLivestreamsForUser(userId) {
  if (!dbPool) {
    console.warn('[DB] MySQL pool non disponible, impossible de fermer le livestream en base pour userId=' + userId);
    return;
  }

  try {
    const [result] = await dbPool.execute(
      'UPDATE livestreams SET end_time = NOW(), is_active = 0 WHERE user_id = ? AND is_active = 1',
      [userId]
    );
    if (result.affectedRows > 0) {
      console.log(`[DB] Livestream actif fermé pour userId=${userId}`);
    }
  } catch (error) {
    console.error('[DB] Erreur lors de la fermeture du livestream actif pour userId=' + userId, error);
  }
}

app.use(cors());
app.use(express.json());

// Store active livestreams and viewers
const livestreams = new Map(); // broadcasterUserId -> { socket, username, streams }
const viewers = new Map();     // viewerUserId -> { socket, watchingUserId }
const socketUserMap = new Map(); // socketId -> { userId, role }

const getUserInfo = (socket) => socketUserMap.get(socket.id) || null;
const getPeerInfo = (socket) => {
  const info = getUserInfo(socket);
  return {
    fromUserId: info ? info.userId : null,
    fromSocketId: socket.id
  };
};

// HTTP endpoints
app.get('/health', (req, res) => {
  res.json({ status: 'ok', timestamp: new Date().toISOString() });
});

app.get('/livestreams', (req, res) => {
  const list = Array.from(livestreams.entries()).map(([userId, data]) => ({
    userId,
    user_id: Number(userId),
    username: data.username
  }));
  res.json({ success: true, livestreams: list });
});

async function loadNsfwModel() {
  if (nsfwModel) return nsfwModel;
  if (!tf || !nsfwjs) {
    console.warn('⚠️ TensorFlow or NSFWJS not loaded, moderation is unavailable');
    return null;
  }
  if (nsfwModelLoading) return null;
  nsfwModelLoading = true;
  try {
    console.log('⏳ Loading NSFWJS model...');
    nsfwModel = await nsfwjs.load();
    console.log('✅ NSFWJS Model Loaded successfully!');
  } catch (err) {
    console.error('❌ Error loading NSFWJS model:', err.message);
  } finally {
    nsfwModelLoading = false;
  }
  return nsfwModel;
}

function containsBlockedText(text) {
  if (!text) return false;
  const blockedKeywords = [
    'porn', 'porno', 'pornographie', 'hentai', 'sex', 'sexe', 'sexuel', 'sexual', 'xxx', 'adult',
    'bdsm', 'erotique', 'erotic', 'erotisme', 'nude', 'nu', 'nue', 'naked', 'seins', 'boobs',
    'tits', 'nichon', 'cul', 'fellation', 'fellatio', 'masturb', 'masturbation', 'penetr', 'penis',
    'pipe', 'bite', 'couilles', 'chatte', 'vagin', 'anus', 'pussy', 'cock', 'ass', 'fetish', 'cum'
  ];
  const lower = text.toLowerCase().replace(/[^a-z0-9\s]/g, ' ');
  return blockedKeywords.some(keyword => {
    const regex = new RegExp('\\b' + keyword + '\\b');
    return regex.test(lower);
  });
}

// Moderation API Endpoint
app.post('/api/moderate', async (req, res) => {
  const { text, filePath } = req.body;
  console.log(`[Moderation] Request received. Text: "${text ? text.substring(0, 30) + '...' : ''}", FilePath: "${filePath || ''}"`);

  // 1. Text moderation
  if (text && containsBlockedText(text)) {
    console.log('[Moderation] ❌ Blocked: Explicit text detected');
    return res.json({ safe: false, reason: 'Le texte contient du contenu explicite interdit.' });
  }

  // 2. Image moderation
  if (filePath) {
    const basename = path.basename(filePath).toLowerCase();
    if (basename.includes('audio')) {
      console.log(`[Moderation] Skipped: Audio file detected: ${filePath}`);
      return res.json({ safe: true });
    }
    if (!fs.existsSync(filePath)) {
      console.warn(`[Moderation] File not found: ${filePath}`);
    } else {
      const ext = path.extname(filePath).toLowerCase();
      const imgExts = ['.jpg', '.jpeg', '.png', '.gif', '.webp', '.jfif', '.avif'];
      const vidExts = ['.mp4', '.webm', '.ogg', '.mov', '.avi'];
      
      if (imgExts.includes(ext)) {
        try {
          const model = await loadNsfwModel();
          if (!model) {
            console.warn('[Moderation] Model unavailable, allowing image upload (fallback safe)');
          } else {
            console.log(`[Moderation] Analyzing image: ${filePath}`);
            const imageBuffer = fs.readFileSync(filePath);
            
            let tensor;
            if (tf && tf.node && tf.node.decodeImage) {
              tensor = tf.node.decodeImage(imageBuffer, 3);
            } else if (tf) {
              // Décodage purement JavaScript pour JPEG (utilisant jpeg-js)
              try {
                const rawImageData = jpeg.decode(imageBuffer, { useTensors: false });
                const { width, height, data } = rawImageData;
                const numChannels = 3;
                const numPixels = width * height;
                const values = new Int32Array(numPixels * numChannels);

                for (let i = 0; i < numPixels; i++) {
                  for (let channel = 0; channel < numChannels; channel++) {
                    values[i * numChannels + channel] = data[i * 4 + channel];
                  }
                }

                tensor = tf.tensor3d(values, [height, width, numChannels], 'int32');
                console.log(`[Moderation] Image decoded successfully using pure JS jpeg-js (${width}x${height})`);
              } catch (decErr) {
                console.error('[Moderation] Pure JS decoding failed (image may not be JPEG):', decErr.message);
              }
            }

            if (tensor) {
              const predictions = await model.classify(tensor);
              tensor.dispose(); // clean up memory
              
              if (predictions && predictions.length > 0) {
                const predMap = {};
                predictions.forEach(p => {
                  predMap[p.className.toLowerCase()] = p.probability;
                });

                const pornScore = predMap['porn'] || 0;
                const hentaiScore = predMap['hentai'] || 0;
                const sexyScore = predMap['sexy'] || 0;
                const neutralScore = predMap['neutral'] || 0;

                const isBlocked = (pornScore > 0.10) || (hentaiScore > 0.10) || (sexyScore > 0.35) || (sexyScore < 0.15 && neutralScore < 0.10);

                console.log(`[Moderation] Image analysis details for ${path.basename(filePath)}:`, predMap);

                if (isBlocked) {
                  console.log('[Moderation] ❌ Blocked: Explicit image detected');
                  return res.json({ 
                    safe: false, 
                    reason: 'L\'image contient du contenu explicite inapproprié.' 
                  });
                }
              }
            }
          }
        } catch (err) {
          console.error('[Moderation] Error classifying image:', err.message);
        }
      } else if (vidExts.includes(ext)) {
        // Modération vidéo via NudeNet (Python)
        console.log(`[Moderation] Launching NudeNet video analysis on: ${filePath}`);
        
        return new Promise((resolve) => {
          const { execFile } = require('child_process');
          const scriptPath = path.join(__dirname, 'moderate_video.py');
          
          const runPython = (cmd) => {
            execFile(cmd, [scriptPath, filePath], (error, stdout, stderr) => {
              if (error) {
                if (cmd === 'python') {
                  console.warn(`[Moderation] 'python' command failed to execute, trying 'python3' fallback...`);
                  return runPython('python3');
                }
                console.error('[Moderation] Video moderation process error:', error.message);
                if (stderr) console.error('[Moderation] Python Stderr:', stderr);
                // Fallback safe en cas d'erreur de NudeNet
                return res.json({ safe: true });
              }
              
              try {
                const result = JSON.parse(stdout.trim());
                console.log(`[Moderation] Video analysis result for ${path.basename(filePath)}:`, result);
                return res.json(result);
              } catch (parseErr) {
                console.error('[Moderation] Failed to parse Python stdout:', stdout);
                return res.json({ safe: true });
              }
            });
          };
          
          runPython('python');
        });
      }
    }
  }

  console.log('[Moderation] ✅ Safe content approved');
  return res.json({ safe: true });
});

// HLS helper functions
function writeM3U8(userId) {
  const liveState = livestreams.get(userId);
  if (!liveState) return;

  const userLiveDir = path.join(__dirname, '..', 'live', String(userId));
  const playlistPath = path.join(userLiveDir, 'playlist.m3u8');

  const seqStart = liveState.seqStart || 0;
  const currentSeq = liveState.currentSeq || 0;

  let m3u8Content = `#EXTM3U\n`;
  m3u8Content += `#EXT-X-VERSION:7\n`;
  m3u8Content += `#EXT-X-TARGETDURATION:4\n`;
  m3u8Content += `#EXT-X-MEDIA-SEQUENCE:${seqStart}\n`;
  m3u8Content += `#EXT-X-MAP:URI="init.mp4"\n`;

  for (let i = seqStart; i <= currentSeq; i++) {
    m3u8Content += `#EXTINF:2.000,\n`;
    m3u8Content += `segment_${i}.mp4\n`;
  }

  try {
    fs.writeFileSync(playlistPath, m3u8Content);
  } catch (err) {
    console.error(`[HLS] Error writing playlist.m3u8 for userId=${userId}:`, err.message);
  }
}

function cleanupLiveDirectory(userId) {
  const userLiveDir = path.join(__dirname, '..', 'live', String(userId));
  if (fs.existsSync(userLiveDir)) {
    try {
      const files = fs.readdirSync(userLiveDir);
      for (const file of files) {
        fs.unlinkSync(path.join(userLiveDir, file));
      }
      fs.rmdirSync(userLiveDir);
      console.log(`[HLS] Cleaned up live directory for userId=${userId}`);
    } catch (err) {
      console.error(`[HLS] Error cleaning up live directory for userId=${userId}:`, err.message);
    }
  }
}

// Socket.io events
io.on('connection', (socket) => {
  console.log(`✓ Client connecté: ${socket.id}`);

  // Broadcaster joins
  socket.on('broadcaster-join', (data) => {
    const { userId, username } = data;
    console.log(`📺 Broadcaster rejoint: userId=${userId}, username=${username}, socketId=${socket.id}`);
    
    livestreams.set(userId, {
      socket: socket,
      username: username,
      streams: new Set(),
      seqStart: 0,
      currentSeq: 0
    });
    socketUserMap.set(socket.id, { userId, role: 'broadcaster' });

    // Notify all clients about new broadcaster
    io.emit('broadcaster-online', { userId, username });
  });

  // Handle HLS chunks from broadcaster
  socket.on('hls-chunk', async (payload) => {
    const { userId, username, seq, data } = payload;
    
    // Ensure broadcaster exists in our livestreams map
    let liveState = livestreams.get(userId);
    if (!liveState) {
      liveState = {
        socket: socket,
        username: username,
        streams: new Set(),
        seqStart: 0,
        currentSeq: 0
      };
      livestreams.set(userId, liveState);
      socketUserMap.set(socket.id, { userId, role: 'broadcaster' });
    }

    const userLiveDir = path.join(__dirname, '..', 'live', String(userId));

    try {
      // If seq is 0, initialize/reset the directory
      if (seq === 0) {
        console.log(`[HLS] Initializing live stream directory for userId=${userId} (${username})`);
        if (fs.existsSync(userLiveDir)) {
          const files = fs.readdirSync(userLiveDir);
          for (const file of files) {
            fs.unlinkSync(path.join(userLiveDir, file));
          }
        } else {
          fs.mkdirSync(userLiveDir, { recursive: true });
        }
        
        // Save chunk 0 as both init.mp4 and segment_0.mp4
        fs.writeFileSync(path.join(userLiveDir, 'init.mp4'), Buffer.from(data));
        fs.writeFileSync(path.join(userLiveDir, 'segment_0.mp4'), Buffer.from(data));
        
        liveState.seqStart = 0;
        liveState.currentSeq = 0;
        
        writeM3U8(userId);
      } else {
        // Save as segment_<seq>.mp4
        fs.writeFileSync(path.join(userLiveDir, `segment_${seq}.mp4`), Buffer.from(data));
        
        liveState.currentSeq = seq;
        
        // Sliding window of 5 segments
        const maxWindow = 5;
        if (seq - liveState.seqStart >= maxWindow) {
          const oldSeq = liveState.seqStart;
          liveState.seqStart = seq - maxWindow + 1;
          
          // Delete old segment file to save disk space
          const oldSegmentPath = path.join(userLiveDir, `segment_${oldSeq}.mp4`);
          if (fs.existsSync(oldSegmentPath)) {
            try {
              fs.unlinkSync(oldSegmentPath);
            } catch (err) {
              console.warn(`[HLS] Could not delete old segment ${oldSeq}:`, err.message);
            }
          }
        }
        
        writeM3U8(userId);
      }
    } catch (err) {
      console.error(`[HLS] Error saving chunk for userId=${userId}:`, err.message);
    }
  });

  socket.on('broadcaster-stop', async () => {
    const info = getUserInfo(socket);
    if (info && info.role === 'broadcaster') {
      const userId = info.userId;
      console.log(`📺 Broadcaster stopped: userId=${userId}`);
      livestreams.delete(userId);
      io.emit('broadcaster-offline', { userId });
      await closeActiveLivestreamsForUser(userId);
      cleanupLiveDirectory(userId);
    }
  });

  // Viewer joins
  socket.on('viewer-join', (data) => {
    const { userId, viewingUserId } = data;
    console.log(`👁️ Viewer rejoint: userId=${userId}, viewingUserId=${viewingUserId}, socketId=${socket.id}`);

    viewers.set(userId, {
      socket: socket,
      watchingUserId: viewingUserId
    });
    socketUserMap.set(socket.id, { userId, role: 'viewer' });

    const broadcast = livestreams.get(viewingUserId);
    if (broadcast) {
      broadcast.streams.add(socket.id);
      broadcast.socket.emit('viewer-joined', { viewerId: userId });
    }
  });

  const findTargetSocket = (target) => {
    if (!target) return null;

    // If target is a socket id, return directly
    if (io.sockets.sockets.has(target)) {
      const socketFound = io.sockets.sockets.get(target);
      console.log(`[Signal] resolve target=${target} (socketId) -> ${socketFound.id}`);
      return socketFound;
    }

    // Try userId lookup for broadcaster or viewer
    const targetId = Number(target);
    if (!Number.isNaN(targetId)) {
      if (livestreams.has(targetId)) {
        const socketFound = livestreams.get(targetId).socket;
        console.log(`[Signal] resolve target=${target} (broadcaster userId) -> ${socketFound.id}`);
        return socketFound;
      }
      if (viewers.has(targetId)) {
        const socketFound = viewers.get(targetId).socket;
        console.log(`[Signal] resolve target=${target} (viewer userId) -> ${socketFound.id}`);
        return socketFound;
      }
    }

    console.warn(`[Signal] resolve target=${target} -> NOT_FOUND`);
    return null;
  };

  // Relay ICE candidates (deprecated for HLS, kept for potential metadata/other peer-to-peer connections)
  socket.on('ice-candidate', (data) => {
    const { to, candidate } = data;
    const targetSocket = findTargetSocket(to);
    const peerInfo = getPeerInfo(socket);
    console.log(`❄️ ICE candidate from ${socket.id} (userId=${peerInfo.fromUserId}) to ${to} -> ${targetSocket ? targetSocket.id : 'NOT_FOUND'}`);
    if (targetSocket) {
      targetSocket.emit('ice-candidate', {
        candidate,
        ...peerInfo
      });
    }
  });

  // Relay SDP offers (deprecated for HLS, kept for compatibility)
  socket.on('offer', (data) => {
    const { to, offer } = data;
    const targetSocket = findTargetSocket(to);
    const peerInfo = getPeerInfo(socket);
    console.log(`📩 Offer from ${socket.id} (userId=${peerInfo.fromUserId}) to ${to} -> ${targetSocket ? targetSocket.id : 'NOT_FOUND'}`);
    if (targetSocket) {
      targetSocket.emit('offer', {
        offer,
        ...peerInfo
      });
    }
  });

  // Relay SDP answers (deprecated for HLS, kept for compatibility)
  socket.on('answer', (data) => {
    const { to, answer } = data;
    const targetSocket = findTargetSocket(to);
    const peerInfo = getPeerInfo(socket);
    console.log(`📨 Answer from ${socket.id} (userId=${peerInfo.fromUserId}) to ${to} -> ${targetSocket ? targetSocket.id : 'NOT_FOUND'}`);
    if (targetSocket) {
      targetSocket.emit('answer', {
        answer,
        ...peerInfo
      });
    }
  });

  // Disconnect handler
  socket.on('disconnect', async () => {
    console.log(`✗ Client déconnecté: ${socket.id}`);

    // Remove broadcaster
    for (const [userId, data] of livestreams.entries()) {
      if (data.socket.id === socket.id) {
        livestreams.delete(userId);
        io.emit('broadcaster-offline', { userId });
        console.log(`📺 Broadcaster offline: userId=${userId}`);
        await closeActiveLivestreamsForUser(userId);
        cleanupLiveDirectory(userId);
        break;
      }
    }

    // Remove viewer
    for (const [userId, data] of viewers.entries()) {
      if (data.socket.id === socket.id) {
        const broadcast = livestreams.get(data.watchingUserId);
        if (broadcast) {
          broadcast.streams.delete(socket.id);
        }
        viewers.delete(userId);
        console.log(`👁️ Viewer offline: userId=${userId}`);
        break;
      }
    }

    socketUserMap.delete(socket.id);
  });

  // Error handler
  socket.on('error', (error) => {
    console.error(`Socket error (${socket.id}):`, error);
  });
});

initDb().then(() => {
  // Pre-load the NSFWJS model
  loadNsfwModel();
  
  server.listen(PORT, () => {
    console.log(`🚀 WebRTC Signaling Server running on http://localhost:${PORT}`);
  });
});

// Graceful shutdown
process.on('SIGTERM', () => {
  console.log('SIGTERM received, shutting down gracefully');
  server.close(() => {
    console.log('Server closed');
    process.exit(0);
  });
});
