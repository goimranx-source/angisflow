/**
 * Prism Chat Widget
 * 
 * Embeddable live chat widget for customer websites.
 * Connects to the webchat backend API for real-time messaging.
 */
(function(window, document) {
    'use strict';

    // Prevent multiple instances
    if (window.PrismChatWidget) {
        return;
    }

    // Configuration
    const API_BASE = window.location.origin;
    const WIDGET_VERSION = '1.0.0';

    // Widget state
    let widgetConfig = null;
    let sessionData = null;
    let isOpen = false;
    let isMinimized = false;
    let socket = null;
    let messageHistory = [];

    // DOM elements
    let widgetContainer = null;
    let chatButton = null;
    let chatWindow = null;
    let messagesContainer = null;
    let messageInput = null;

    /**
     * Initialize the chat widget
     */
    function init() {
        const config = window.PrismChat || {};
        const widgetKey = config.key || getWidgetKeyFromScript();

        if (!widgetKey) {
            console.error('PrismChat: Widget key not found');
            return;
        }

        // Load widget configuration
        loadWidgetConfig(widgetKey)
            .then(data => {
                widgetConfig = data.widget;
                sessionData = data.session;
                createWidget();
                setupEventListeners();
            })
            .catch(error => {
                console.error('PrismChat: Failed to initialize widget', error);
            });
    }

    /**
     * Get widget key from script URL
     */
    function getWidgetKeyFromScript() {
        const scripts = document.getElementsByTagName('script');
        for (let script of scripts) {
            const src = script.src;
            if (src && src.includes('/chat/widget.js')) {
                const url = new URL(src);
                return url.searchParams.get('k');
            }
        }
        return null;
    }

    /**
     * Load widget configuration from API
     */
    async function loadWidgetConfig(widgetKey) {
        const response = await fetch(`${API_BASE}/api/v1/chat/boot?widget_key=${widgetKey}`, {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
            },
        });

        if (!response.ok) {
            throw new Error('Failed to load widget configuration');
        }

        return await response.json();
    }

    /**
     * Create the widget DOM structure
     */
    function createWidget() {
        // Create main container
        widgetContainer = document.createElement('div');
        widgetContainer.id = 'prism-chat-widget';
        widgetContainer.innerHTML = `
            <style>
                #prism-chat-widget {
                    position: fixed;
                    bottom: 20px;
                    right: 20px;
                    z-index: 999999;
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                }
                
                .prism-chat-button {
                    width: 60px;
                    height: 60px;
                    border-radius: 50%;
                    background: ${widgetConfig.primary_color || '#3b82f6'};
                    color: white;
                    border: none;
                    cursor: pointer;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-size: 24px;
                    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                    transition: all 0.2s ease;
                }
                
                .prism-chat-button:hover {
                    transform: scale(1.05);
                    box-shadow: 0 6px 16px rgba(0,0,0,0.2);
                }
                
                .prism-chat-window {
                    position: absolute;
                    bottom: 80px;
                    right: 0;
                    width: 350px;
                    height: 500px;
                    background: white;
                    border-radius: 12px;
                    box-shadow: 0 8px 32px rgba(0,0,0,0.15);
                    display: none;
                    flex-direction: column;
                    overflow: hidden;
                }
                
                .prism-chat-header {
                    background: ${widgetConfig.primary_color || '#3b82f6'};
                    color: white;
                    padding: 16px;
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                }
                
                .prism-chat-header h3 {
                    margin: 0;
                    font-size: 16px;
                    font-weight: 600;
                }
                
                .prism-chat-close {
                    background: none;
                    border: none;
                    color: white;
                    font-size: 20px;
                    cursor: pointer;
                    padding: 0;
                    line-height: 1;
                }
                
                .prism-chat-messages {
                    flex: 1;
                    overflow-y: auto;
                    padding: 16px;
                    display: flex;
                    flex-direction: column;
                    gap: 12px;
                }
                
                .prism-chat-message {
                    max-width: 80%;
                    padding: 12px;
                    border-radius: 12px;
                    font-size: 14px;
                    line-height: 1.4;
                }
                
                .prism-chat-message.customer {
                    align-self: flex-end;
                    background: ${widgetConfig.primary_color || '#3b82f6'};
                    color: white;
                }
                
                .prism-chat-message.agent {
                    align-self: flex-start;
                    background: #f1f5f9;
                    color: #334155;
                }
                
                .prism-chat-input-container {
                    padding: 16px;
                    border-top: 1px solid #e2e8f0;
                    display: flex;
                    gap: 8px;
                }
                
                .prism-chat-input {
                    flex: 1;
                    padding: 12px;
                    border: 1px solid #e2e8f0;
                    border-radius: 8px;
                    font-size: 14px;
                    outline: none;
                    resize: none;
                    max-height: 100px;
                }
                
                .prism-chat-send {
                    background: ${widgetConfig.primary_color || '#3b82f6'};
                    color: white;
                    border: none;
                    padding: 12px 16px;
                    border-radius: 8px;
                    cursor: pointer;
                    font-size: 14px;
                    font-weight: 500;
                }
                
                .prism-chat-typing {
                    align-self: flex-start;
                    background: #f1f5f9;
                    color: #64748b;
                    padding: 8px 12px;
                    border-radius: 12px;
                    font-size: 12px;
                    font-style: italic;
                }
                
                @media (max-width: 480px) {
                    #prism-chat-widget {
                        bottom: 10px;
                        right: 10px;
                        left: 10px;
                    }
                    
                    .prism-chat-window {
                        width: 100%;
                        height: 70vh;
                        bottom: 70px;
                        left: 0;
                        right: 0;
                    }
                }
            </style>
            
            <button class="prism-chat-button" id="prism-chat-button">
                💬
            </button>
            
            <div class="prism-chat-window" id="prism-chat-window">
                <div class="prism-chat-header">
                    <h3>${widgetConfig.title || 'Chat with us'}</h3>
                    <button class="prism-chat-close" id="prism-chat-close">×</button>
                </div>
                
                <div class="prism-chat-messages" id="prism-chat-messages">
                    <div class="prism-chat-message agent">
                        ${widgetConfig.welcome_message || 'Hello! How can we help you today?'}
                    </div>
                </div>
                
                <div class="prism-chat-input-container">
                    <textarea class="prism-chat-input" id="prism-chat-input" 
                              placeholder="Type your message..." rows="1"></textarea>
                    <button class="prism-chat-send" id="prism-chat-send">Send</button>
                </div>
            </div>
        `;

        // Append to body
        document.body.appendChild(widgetContainer);

        // Get references to elements
        chatButton = document.getElementById('prism-chat-button');
        chatWindow = document.getElementById('prism-chat-window');
        messagesContainer = document.getElementById('prism-chat-messages');
        messageInput = document.getElementById('prism-chat-input');
    }

    /**
     * Set up event listeners
     */
    function setupEventListeners() {
        // Chat button click
        chatButton.addEventListener('click', toggleChat);

        // Close button click
        document.getElementById('prism-chat-close').addEventListener('click', closeChat);

        // Send button click
        document.getElementById('prism-chat-send').addEventListener('click', sendMessage);

        // Enter key in input
        messageInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });

        // Auto-resize textarea
        messageInput.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 100) + 'px';
        });

        // Click outside to close (optional)
        document.addEventListener('click', function(e) {
            if (isOpen && !widgetContainer.contains(e.target)) {
                // closeChat(); // Uncomment if you want click-outside-to-close
            }
        });
    }

    /**
     * Toggle chat window
     */
    function toggleChat() {
        if (isOpen) {
            closeChat();
        } else {
            openChat();
        }
    }

    /**
     * Open chat window
     */
    function openChat() {
        isOpen = true;
        chatWindow.style.display = 'flex';
        chatButton.textContent = '×';
        messageInput.focus();

        // Start conversation if not already started
        if (!sessionData || !sessionData.conversation_id) {
            startConversation();
        } else {
            loadMessageHistory();
        }
    }

    /**
     * Close chat window
     */
    function closeChat() {
        isOpen = false;
        chatWindow.style.display = 'none';
        chatButton.textContent = '💬';
    }

    /**
     * Start a new conversation
     */
    async function startConversation() {
        try {
            const response = await fetch(`${API_BASE}/api/v1/chat/start`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    widget_key: window.PrismChat.key,
                    visitor_token: sessionData?.visitor_token,
                    page_url: window.location.href,
                    page_title: document.title,
                    referrer: document.referrer,
                    user_agent: navigator.userAgent,
                }),
            });

            if (response.ok) {
                const data = await response.json();
                sessionData = data.session;
                
                // Store session data in localStorage
                localStorage.setItem('prism_chat_session', JSON.stringify(sessionData));
            }
        } catch (error) {
            console.error('PrismChat: Failed to start conversation', error);
            addSystemMessage('Failed to connect. Please refresh and try again.');
        }
    }

    /**
     * Send a message
     */
    async function sendMessage() {
        const message = messageInput.value.trim();
        if (!message) return;

        // Clear input
        messageInput.value = '';
        messageInput.style.height = 'auto';

        // Add message to UI immediately
        addMessage(message, 'customer');

        try {
            const response = await fetch(`${API_BASE}/api/v1/chat/send`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    visitor_token: sessionData.visitor_token,
                    message: message,
                }),
            });

            if (!response.ok) {
                throw new Error('Failed to send message');
            }

            // Show typing indicator
            showTypingIndicator();

            // Poll for new messages
            setTimeout(() => {
                hideTypingIndicator();
                pollForMessages();
            }, 1000);

        } catch (error) {
            console.error('PrismChat: Failed to send message', error);
            addSystemMessage('Failed to send message. Please try again.');
        }
    }

    /**
     * Add message to the chat
     */
    function addMessage(text, sender) {
        const messageDiv = document.createElement('div');
        messageDiv.className = `prism-chat-message ${sender}`;
        messageDiv.textContent = text;
        
        messagesContainer.appendChild(messageDiv);
        scrollToBottom();
    }

    /**
     * Add system message
     */
    function addSystemMessage(text) {
        const messageDiv = document.createElement('div');
        messageDiv.className = 'prism-chat-message agent';
        messageDiv.style.fontStyle = 'italic';
        messageDiv.style.opacity = '0.8';
        messageDiv.textContent = text;
        
        messagesContainer.appendChild(messageDiv);
        scrollToBottom();
    }

    /**
     * Show typing indicator
     */
    function showTypingIndicator() {
        const typingDiv = document.createElement('div');
        typingDiv.id = 'prism-typing-indicator';
        typingDiv.className = 'prism-chat-typing';
        typingDiv.textContent = 'Agent is typing...';
        
        messagesContainer.appendChild(typingDiv);
        scrollToBottom();
    }

    /**
     * Hide typing indicator
     */
    function hideTypingIndicator() {
        const typingDiv = document.getElementById('prism-typing-indicator');
        if (typingDiv) {
            typingDiv.remove();
        }
    }

    /**
     * Poll for new messages
     */
    async function pollForMessages() {
        if (!sessionData?.visitor_token) return;

        try {
            const response = await fetch(`${API_BASE}/api/v1/chat/transcript?visitor_token=${sessionData.visitor_token}`);
            
            if (response.ok) {
                const data = await response.json();
                const messages = data.messages || [];
                
                // Add new messages
                messages.forEach(msg => {
                    if (!messageHistory.includes(msg.id)) {
                        if (msg.sender_type === 'staff') {
                            addMessage(msg.content, 'agent');
                        }
                        messageHistory.push(msg.id);
                    }
                });
            }
        } catch (error) {
            console.error('PrismChat: Failed to poll messages', error);
        }
    }

    /**
     * Load message history
     */
    async function loadMessageHistory() {
        if (!sessionData?.visitor_token) return;

        try {
            const response = await fetch(`${API_BASE}/api/v1/chat/transcript?visitor_token=${sessionData.visitor_token}`);
            
            if (response.ok) {
                const data = await response.json();
                const messages = data.messages || [];
                
                // Clear current messages except welcome
                const welcomeMsg = messagesContainer.querySelector('.prism-chat-message.agent');
                messagesContainer.innerHTML = '';
                if (welcomeMsg) {
                    messagesContainer.appendChild(welcomeMsg);
                }
                
                // Add historical messages
                messages.forEach(msg => {
                    const sender = msg.sender_type === 'staff' ? 'agent' : 'customer';
                    addMessage(msg.content, sender);
                    messageHistory.push(msg.id);
                });
            }
        } catch (error) {
            console.error('PrismChat: Failed to load message history', error);
        }
    }

    /**
     * Scroll messages to bottom
     */
    function scrollToBottom() {
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }

    /**
     * Check for stored session
     */
    function checkStoredSession() {
        try {
            const stored = localStorage.getItem('prism_chat_session');
            if (stored) {
                sessionData = JSON.parse(stored);
            }
        } catch (error) {
            console.error('PrismChat: Failed to load stored session', error);
        }
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            checkStoredSession();
            init();
        });
    } else {
        checkStoredSession();
        init();
    }

    // Export widget API
    window.PrismChatWidget = {
        open: openChat,
        close: closeChat,
        toggle: toggleChat,
        version: WIDGET_VERSION,
    };

})(window, document);