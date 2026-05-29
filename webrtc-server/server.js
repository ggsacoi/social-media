const express = require('express');
const app = express();
const http = require('http');
const socketIO = require('socket.io');
const cors = require('cors');
const mysql = require('mysql2/promise');

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
    username: data.username
  }));
  res.json({ success: true, livestreams: list });
});

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
      streams: new Set()
    });
    socketUserMap.set(socket.id, { userId, role: 'broadcaster' });

    // Notify all clients about new broadcaster
    io.emit('broadcaster-online', { userId, username });
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

  // Relay ICE candidates
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

  // Relay SDP offers
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

  // Relay SDP answers
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
