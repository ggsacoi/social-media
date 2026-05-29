# WebRTC Livestream Server

Serveur de signalisation pour la transmission en direct WebRTC.

## Installation

```bash
cd webrtc-server
npm install
```

## Démarrage

```bash
npm start
```

Le serveur écoute sur `http://localhost:3000` par défaut.

## Architecture

- **server.js** : Serveur Express + Socket.io
- **client.js** : Client WebRTC pour navigateurs

## Endpoints

- `GET /health` : Vérifier l'état du serveur
- `GET /livestreams` : Lister les livestreams actifs
- Socket.io events :
  - `broadcaster-join` : Démarrer une diffusion
  - `viewer-join` : Rejoindre une diffusion
  - `offer`, `answer`, `ice-candidate` : Signalisation WebRTC

## Utilisation

Le client WebRTC se connecte automatiquement et expose `window.WebRTCClient` :

```javascript
// Démarrer un broadcast
await window.WebRTCClient.connect();
const stream = await window.WebRTCClient.startBroadcaster(userId, username);

// Regarder un livestream
await window.WebRTCClient.connect();
await window.WebRTCClient.startViewer(viewerId, broadcasterUserId, videoElement);
```
