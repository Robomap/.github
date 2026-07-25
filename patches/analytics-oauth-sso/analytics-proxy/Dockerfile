FROM nginx:1.27-alpine
COPY nginx.conf /etc/nginx/conf.d/default.conf
COPY login.html /usr/share/nginx/html/login.html
COPY signup.html /usr/share/nginx/html/signup.html
COPY oauth-callback.html /usr/share/nginx/html/oauth-callback.html
COPY styles.css /usr/share/nginx/html/auth-ui/styles.css
COPY auth.js /usr/share/nginx/html/auth-ui/auth.js
COPY robomap_logo_text_white.png /usr/share/nginx/html/auth-ui/robomap_logo_text_white.png
COPY favicon.ico /usr/share/nginx/html/auth-ui/favicon.ico
EXPOSE 8080
