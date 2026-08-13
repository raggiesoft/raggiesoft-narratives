After running Claire (the Cloud Init script):

Here are the step-by-step instructions to replace the temporary self-signed certificates on your Droplet with a proper Cloudflare Origin CA certificate.

### 1. Generate the Origin CA Certificate on Cloudflare

- Log in to your Cloudflare dashboard, select your domain, and navigate to **SSL/TLS > Origin Server**.
    
- Click the **Create Certificate** button.
    
- Leave the default option of **Generate private key and CSR with Cloudflare** selected.
    
- Ensure your hostnames are correct; the zone apex (e.g., `raggiesoft.com`) and a first-level wildcard (e.g., `*.raggiesoft.com`) are included by default.
    
- Leave the validity period as desired and click **Create**.
    
- Ensure the **Key Format** is set to **PEM**, which is the format expected by Nginx.
    
- Keep this browser window open. You will need to copy both the Origin Certificate and the Private Key into separate files on your server. _Note: For security reasons, Cloudflare will not display the Private Key again once you exit this screen_.
    

### 2. Replace the Temporary Certificates on Your Droplet

Since your `cloud-init` script already created the `/etc/nginx/ssl` directory and generated placeholder files, you just need to overwrite their contents.

- SSH into your Droplet.
    
- Open the certificate file in a text editor like `nano`:
    
    Bash
    
    ```
    sudo nano /etc/nginx/ssl/raggiesoft.pem
    ```
    
- Delete the existing self-signed certificate block, paste the **Origin Certificate** provided by Cloudflare, and save the file. Ensure no extra blank lines are accidentally inserted, as Nginx will treat the certificate as invalid.
    
- Next, open the private key file:
    
    Bash
    
    ```
    sudo nano /etc/nginx/ssl/raggiesoft.key
    ```
    
- Delete the existing key block, paste the **Private Key** provided by Cloudflare, and save the file. Again, ensure there are no blank lines.
    

### 3. Restart Nginx and Update Cloudflare Settings

- Verify that your Nginx configuration syntax is valid:
    
    Bash
    
    ```
    sudo nginx -t
    ```
    
- If the test is successful, restart the Nginx service to load the new Cloudflare certificates:
    
    Bash
    
    ```
    sudo systemctl restart nginx
    ```
    
- Finally, return to the Cloudflare dashboard, navigate to **SSL/TLS > Overview**, and change your SSL/TLS encryption mode to **Full (strict)**. This instructs Cloudflare to strictly encrypt all traffic and authenticate your newly installed Origin CA certificate on the Nginx server.