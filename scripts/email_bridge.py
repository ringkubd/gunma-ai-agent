import imaplib
import email
import requests
import time
import os
import json
from email.header import decode_header

# --- CONFIGURATION ---
IMAP_SERVER = "mail.privateemail.com" # Namecheap Private Email
IMAP_USER = "support@gunmahalalfood.com" 
IMAP_PASS = "Support@gunma.mail360"
WEBHOOK_URL = "https://beta-api.gunmahalalfood.com/api/chat/webhook/email"
POLL_INTERVAL = 10 # Seconds (fallback if IDLE is not used)

def get_body(msg):
    if msg.is_multipart():
        for part in msg.walk():
            ctype = part.get_content_type()
            cdisp = str(part.get('Content-Disposition'))
            if ctype == 'text/plain' and 'attachment' not in cdisp:
                return part.get_payload(decode=True).decode()
    else:
        return msg.get_payload(decode=True).decode()
    return ""

def process_emails():
    try:
        mail = imaplib.IMAP4_SSL(IMAP_SERVER)
        mail.login(IMAP_USER, IMAP_PASS)
        mail.select("inbox")

        # Search for unseen emails
        status, messages = mail.search(None, '(UNSEEN)')
        if status != 'OK':
            return

        for num in messages[0].split():
            status, data = mail.fetch(num, '(RFC822)')
            if status != 'OK':
                continue

            raw_email = data[0][1]
            msg = email.message_from_bytes(raw_email)

            # Decode Subject
            subject, encoding = decode_header(msg["Subject"])[0]
            if isinstance(subject, bytes):
                subject = subject.decode(encoding if encoding else "utf-8")

            # Decode Sender
            sender = msg.get("From")
            
            # Extract plain text body
            body = get_body(msg)

            print(f"[*] New Email from {sender}: {subject}")

            # Send to Laravel Webhook
            payload = {
                "sender": sender,
                "subject": subject,
                "body-plain": body
            }

            try:
                response = requests.post(WEBHOOK_URL, json=payload, timeout=10)
                if response.status_code == 200:
                    print(f"[+] Successfully forwarded to AI agent")
                    # Mark as seen
                    mail.store(num, '+FLAGS', '\\Seen')
                else:
                    print(f"[-] Webhook failed with status: {response.status_code}")
            except Exception as e:
                print(f"[-] Error calling webhook: {e}")

        mail.logout()
    except Exception as e:
        print(f"[-] IMAP Error: {e}")

if __name__ == "__main__":
    print(f"Starting Gunma Email Bridge for {IMAP_USER}...")
    while True:
        process_emails()
        time.sleep(POLL_INTERVAL)
