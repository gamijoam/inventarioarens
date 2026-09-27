package server

import (
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"strings"
	"sync"
	"time"

	"github.com/gorilla/websocket"
	"inventarioarens/ws-hub/pkg/hub"
)

type Server struct {
	port     int
	hub      *hub.Hub
	authKey  string
	mux      *http.ServeMux
	upgrader websocket.Upgrader
}

type wsClient struct {
	id       string
	hub      *hub.Hub
	conn     *websocket.Conn
	sendChan chan []byte
	mu       sync.Mutex
	closed   bool
}

func (c *wsClient) ID() string {
	return c.id
}

func (c *wsClient) Send(msg []byte) {
	c.mu.Lock()
	defer c.mu.Unlock()
	if c.closed {
		return
	}
	select {
	case c.sendChan <- msg:
	default:
		// Drop message if buffer is full to prevent blocking
	}
}

func (c *wsClient) close() {
	c.mu.Lock()
	defer c.mu.Unlock()
	if !c.closed {
		c.closed = true
		close(c.sendChan)
	}
}

func NewServer(port int, h *hub.Hub, authKey string) *Server {
	s := &Server{
		port:    port,
		hub:     h,
		authKey: authKey,
		mux:     http.NewServeMux(),
		upgrader: websocket.Upgrader{
			ReadBufferSize:  1024,
			WriteBufferSize: 1024,
			CheckOrigin: func(r *http.Request) bool {
				return true // Permit all origins (Electron, Web, Local POS)
			},
		},
	}

	s.mux.HandleFunc("/health", s.handleHealth)
	s.mux.HandleFunc("/publish", s.handlePublish)
	s.mux.HandleFunc("/ws", s.handleWS)

	return s
}

func (s *Server) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	s.mux.ServeHTTP(w, r)
}

func (s *Server) handleHealth(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/json")
	_ = json.NewEncoder(w).Encode(map[string]interface{}{
		"ok":       true,
		"service":  "ws-hub",
		"clients":  s.hub.ClientCount(),
		"channels": s.hub.ChannelCount(),
	})
}

type PublishRequest struct {
	Channel string      `json:"channel"`
	Event   string      `json:"event"`
	Data    interface{} `json:"data,omitempty"`
}

func (s *Server) handlePublish(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		http.Error(w, `{"error":"Method not allowed"}`, http.StatusMethodNotAllowed)
		return
	}

	if s.authKey != "" {
		key := r.Header.Get("X-Auth-Key")
		if key == "" {
			key = r.URL.Query().Get("key")
		}
		if key != s.authKey {
			w.Header().Set("Content-Type", "application/json")
			w.WriteHeader(http.StatusUnauthorized)
			_, _ = w.Write([]byte(`{"error":"Unauthorized"}`))
			return
		}
	}

	body, err := io.ReadAll(r.Body)
	if err != nil {
		http.Error(w, `{"error":"Cannot read body"}`, http.StatusBadRequest)
		return
	}
	defer r.Body.Close()

	var req PublishRequest
	if err := json.Unmarshal(body, &req); err != nil || req.Channel == "" {
		http.Error(w, `{"error":"Invalid request payload, 'channel' required"}`, http.StatusBadRequest)
		return
	}

	delivered := s.hub.Broadcast(req.Channel, body)

	w.Header().Set("Content-Type", "application/json")
	_ = json.NewEncoder(w).Encode(map[string]interface{}{
		"ok":        true,
		"channel":   req.Channel,
		"delivered": delivered,
	})
}

func generateID() string {
	b := make([]byte, 16)
	if _, err := rand.Read(b); err != nil {
		return fmt.Sprintf("%d", time.Now().UnixNano())
	}
	return hex.EncodeToString(b)
}

func (s *Server) handleWS(w http.ResponseWriter, r *http.Request) {
	conn, err := s.upgrader.Upgrade(w, r, nil)
	if err != nil {
		return
	}

	client := &wsClient{
		id:       generateID(),
		hub:      s.hub,
		conn:     conn,
		sendChan: make(chan []byte, 256),
	}

	s.hub.Register(client)

	// Subscribe to initial channels from query string ?channels=ch1,ch2
	channelsQuery := r.URL.Query().Get("channels")
	if channelsQuery != "" {
		chList := strings.Split(channelsQuery, ",")
		for _, ch := range chList {
			ch = strings.TrimSpace(ch)
			if ch != "" {
				s.hub.Subscribe(client, ch)
			}
		}
	}

	// Start writer pump
	go s.writePump(client)
	// Start reader pump (blocks until disconnect)
	go s.readPump(client)
}

func (s *Server) writePump(c *wsClient) {
	ticker := time.NewTicker(30 * time.Second)
	defer func() {
		ticker.Stop()
		_ = c.conn.Close()
	}()

	for {
		select {
		case msg, ok := <-c.sendChan:
			_ = c.conn.SetWriteDeadline(time.Now().Add(10 * time.Second))
			if !ok {
				_ = c.conn.WriteMessage(websocket.CloseMessage, []byte{})
				return
			}

			w, err := c.conn.NextWriter(websocket.TextMessage)
			if err != nil {
				return
			}
			_, _ = w.Write(msg)

			// Drain any additional buffered messages into current frame
			n := len(c.sendChan)
			for i := 0; i < n; i++ {
				_, _ = w.Write([]byte{'\n'})
				_, _ = w.Write(<-c.sendChan)
			}

			if err := w.Close(); err != nil {
				return
			}

		case <-ticker.C:
			_ = c.conn.SetWriteDeadline(time.Now().Add(10 * time.Second))
			if err := c.conn.WriteMessage(websocket.PingMessage, nil); err != nil {
				return
			}
		}
	}
}

type ClientAction struct {
	Action  string `json:"action"` // "subscribe" or "unsubscribe"
	Channel string `json:"channel"`
}

func (s *Server) readPump(c *wsClient) {
	defer func() {
		s.hub.Unregister(c)
		c.close()
		_ = c.conn.Close()
	}()

	c.conn.SetReadLimit(65536)
	_ = c.conn.SetReadDeadline(time.Now().Add(60 * time.Second))
	c.conn.SetPongHandler(func(string) error {
		_ = c.conn.SetReadDeadline(time.Now().Add(60 * time.Second))
		return nil
	})

	for {
		_, msg, err := c.conn.ReadMessage()
		if err != nil {
			break
		}

		// Handle dynamic subscribe / unsubscribe commands from client
		var action ClientAction
		if err := json.Unmarshal(msg, &action); err == nil && action.Channel != "" {
			switch action.Action {
			case "subscribe":
				s.hub.Subscribe(c, action.Channel)
			case "unsubscribe":
				s.hub.Unsubscribe(c, action.Channel)
			}
		}
	}
}
