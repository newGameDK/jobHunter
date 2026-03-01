import requests
from bs4 import BeautifulSoup
import json
import datetime
import os
import threading
import tkinter as tk
from tkinter import simpledialog, scrolledtext
import random
import time
import re
from urllib.parse import urljoin

JSON_FILE = 'jobs.json'
URL_FILE = 'last_url.txt'

BASE_URL = ''
FIRST_PAGE_URL = ''
DEBUG_SAVE_HTML = True  # gemmer debug_pageX.html når en side ikke giver mening

# ---------- HTTP session ----------
SESSION = requests.Session()
SESSION.headers.update({
    "User-Agent": (
        "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
        "AppleWebKit/537.36 (KHTML, like Gecko) "
        "Chrome/124.0.0.0 Safari/537.36"
    ),
    "Accept-Language": "da,da-DK;q=0.9,en;q=0.8",
    "Referer": "https://www.jobindex.dk/"
})

# Kun de to gyldige mønstre for jobdetail på Jobindex
DETAIL_HREF_RE = re.compile(r"(?:^|/)vis-job/|(?:^|/)jobannonce/sign/