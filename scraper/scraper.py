import json
import os
import re
import sys
import base64
import time
from datetime import datetime
from html import unescape
from urllib.parse import parse_qs, quote_plus, urljoin, urlparse

ROOT_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
LOCAL_PACKAGE_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), "python_packages")
VENDOR_DIR = os.path.join(ROOT_DIR, "vendor", "python")
for package_dir in (VENDOR_DIR, LOCAL_PACKAGE_DIR):
    if os.path.isdir(package_dir) and package_dir not in sys.path:
        sys.path.insert(0, package_dir)

# Some local Windows/PHP environments set a dead proxy such as 127.0.0.1:9.
# The main scraper session ignores proxies; clear them for third-party search
# packages too, otherwise Google discovery fails before domain guessing starts.
for proxy_var in ("HTTP_PROXY", "HTTPS_PROXY", "ALL_PROXY", "http_proxy", "https_proxy", "all_proxy"):
    os.environ.pop(proxy_var, None)

import pymysql
import requests
from bs4 import BeautifulSoup

try:
    from googlesearch import search as google_search
except Exception:
    google_search = None


BLOCKED_HOSTS = {
    "facebook.com",
    "instagram.com",
    "linkedin.com",
    "youtube.com",
    "wikipedia.org",
    "shiksha.com",
    "careers360.com",
    "collegedunia.com",
    "getmyuni.com",
    "collegebatch.com",
    "collegedekho.com",
    "indcareer.com",
    "indiacollegeshub.com",
    "studyguideindia.com",
    "justdial.com",
    "bing.com",
    "google.com",
    "duckduckgo.com",
}

DIRECTORY_HOSTS = BLOCKED_HOSTS.intersection({
    "shiksha.com",
    "careers360.com",
    "collegedunia.com",
    "getmyuni.com",
    "collegebatch.com",
    "collegedekho.com",
    "indcareer.com",
    "indiacollegeshub.com",
    "studyguideindia.com",
})

TRUSTED_SOURCE_HOSTS = set()

SOURCE_BRAND_TERMS = {
    "shiksha", "careers360", "collegedunia", "getmyuni", "collegebatch",
    "collegedekho", "indcareer", "indiacollegeshub", "studyguideindia",
}

EDUCATION_TERMS = (
    "college", "university", "institute", "institution", "campus", "admission",
    "admissions", "courses", "departments", "faculty", "students", "academics",
    "affiliated", "approved", "ugc", "aicte", "naac", "nirf", "prospectus",
    "placement", "placements", "scholarship", "examination", "semester",
)

GENERIC_COLLEGE_NAME_TERMS = {
    "college", "institute", "institution", "university", "school", "academy",
    "education", "educational", "engineering", "technology", "science", "arts",
    "commerce", "medical", "dental", "pharmacy", "polytechnic",
    "department", "faculty", "campus", "studies",
}

COMPANY_TERMS = (
    "private limited", "pvt ltd", "pvt. ltd", "ltd.", "llp", "company",
    "manufacturer", "supplier", "exporter", "importer", "trader", "dealer",
    "corporate office", "gst", "cin ", "real estate", "consultancy",
    "software services", "digital marketing", "ecommerce", "shop", "store",
)

COACHING_TERMS = (
    "ias", "upsc", "ssc", "bank po", "neet coaching", "jee coaching",
    "coaching centre", "coaching center", "coaching institute", "test series",
    "civil services", "spoken english", "training centre",
    "training center", "tutorial", "tuition classes", "competitive exam",
)

HARD_COACHING_TERMS = (
    "upsc", "civil services", "coaching centre", "coaching center",
    "coaching institute", "test series", "competitive exam",
)

COURSES = [
    "B.Tech", "M.Tech", "MBA", "BBA", "BCA", "MCA", "B.Com", "M.Com",
    "BA", "MA", "B.Sc", "M.Sc", "LLB", "LLM", "MBBS", "BDS", "B.Pharm",
    "M.Pharm", "B.Ed", "M.Ed", "PhD", "PGDM", "Diploma", "B.Arch",
    "M.Arch", "B.Des", "M.Des", "BPT", "MPT", "GNM", "ANM", "D.EL.ED",
    "D.El.Ed", "D.Ed", "B.P.Ed", "C.P.Ed", "D.P.Ed", "M.P.Ed",
]

FACILITY_WORDS = [
    "Library", "Hostel", "Laboratory", "Labs", "Computer Lab", "Sports",
    "Transport", "Cafeteria", "Auditorium", "Wi-Fi", "Medical", "Placement",
    "Scholarship", "Smart Class", "Seminar Hall", "Gym", "Bank", "ATM",
]

FEE_TERMS = (
    "fee", "fees", "fee structure", "tuition", "course fee", "annual fee",
    "semester fee", "hostel fee", "prospectus", "brochure",
)

LINK_KEYWORDS = {
    "admission": ("admission", "apply", "enquiry", "registration"),
    "courses": ("course", "courses", "program", "programme", "academics", "department"),
    "fees": ("fee", "fees", "tuition", "prospectus"),
    "contact": ("contact", "location", "reach-us", "contact-us"),
    "placement": ("placement", "career", "training"),
    "gallery": ("gallery", "photo", "campus", "infrastructure"),
    "prospectus": ("prospectus", "brochure", "download"),
    "scholarship": ("scholarship", "financial aid"),
    "hostel": ("hostel", "residence", "accommodation"),
}

STATES = [
    "Andhra Pradesh", "Arunachal Pradesh", "Assam", "Bihar", "Chhattisgarh",
    "Delhi", "Goa", "Gujarat", "Haryana", "Himachal Pradesh", "Jharkhand",
    "Karnataka", "Kerala", "Madhya Pradesh", "Maharashtra", "Odisha",
    "Punjab", "Rajasthan", "Tamil Nadu", "Telangana", "Uttar Pradesh",
    "Uttarakhand", "West Bengal",
]

CITIES = [
    "New Delhi", "Delhi", "Mumbai", "Pune", "Bengaluru", "Bangalore","Haryana",
    "Hyderabad", "Chennai", "Kolkata", "Jaipur", "Lucknow", "Ahmedabad",
    "Indore", "Bhopal", "Patna", "Noida", "Gurugram", "Gurgaon",
    "Chandigarh", "Gwalior", "Kanpur", "Nagpur", "Surat", "Vadodara",
    "Rohtak", "Meerut", "Varanasi", "Coimbatore", "Madurai", "Mysuru",
    "Mangalore", "Kochi", "Thiruvananthapuram", "Bhubaneswar", "Ranchi",
    "Raipur", "Jodhpur", "Udaipur", "Dehradun", "Jalandhar", "Amritsar",
    "Jhajjar", "Sonipat", "Panipat", "Karnal", "Hisar", "Ambala",
    "Faridabad", "Ghaziabad", "Agra", "Allahabad", "Prayagraj", "Bareilly",
    "Moradabad", "Aligarh", "Mathura", "Vellore", "Tiruchirappalli",
    "Jind", "Julana", "Kaithal", "Kurukshetra", "Rewari", "Yamunanagar",
    "Sirsa", "Fatehabad", "Bhiwani", "Charkhi Dadri", "Nuh", "Palwal",
]

BAD_SEARCH_URL_PARTS = (
    "wiktionary.org", "dictionary.cambridge", "merriam-webster.com",
    "britannica.com/topic", "vedantu.com/general-knowledge",
    "duolingo.com/english-alphabet", "microsoft.com/en-us/privacy",
    "support.microsoft.com", "the-letter-a", "the-letter-s",
    "/dictionary/english/a", "/dictionary/english/s", "alphabet-jam",
    "pronunciation-a-english", "flipkart.com", "imdb.com/name",
    "allevents.in", "bookmyshow.com", "10times.com", "stock.yahoo.com",
    "ahmedabadmirror.com", "endlessevent.com", "stayhappening.com",
    "werindia.com/events", "ahemdabad.com/events",
)


def log(message):
    path = os.path.join(ROOT_DIR, "logs", "python-scraper.log")
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, "a", encoding="utf-8") as handle:
        handle.write(f"[{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] {message}\n")


def load_config():
    path = os.path.join(os.path.dirname(os.path.abspath(__file__)), "scraper_config.json")
    with open(path, "r", encoding="utf-8") as handle:
        return json.load(handle)


def db_connect(config):
    db = config["db"]
    return pymysql.connect(
        host=db.get("host", "localhost"),
        user=db.get("user", "root"),
        password=db.get("password", ""),
        database=db.get("database", "top_college"),
        charset=db.get("charset", "utf8mb4"),
        cursorclass=pymysql.cursors.DictCursor,
        autocommit=False,
    )


def ensure_runtime_schema(cursor):
    cursor.execute("SHOW COLUMNS FROM colleges")
    columns = {row["Field"] for row in cursor.fetchall()}
    if "scrape_started_at" not in columns:
        cursor.execute("ALTER TABLE colleges ADD COLUMN scrape_started_at DATETIME DEFAULT NULL")


def execute_with_deadlock_retry(cursor, sql, values=(), attempts=3):
    for attempt in range(attempts):
        try:
            cursor.execute(sql, values)
            return
        except pymysql.err.OperationalError as exc:
            if exc.args and exc.args[0] in (1205, 1213) and attempt < attempts - 1:
                time.sleep(0.8 * (attempt + 1))
                continue
            raise


def clean_text(text):
    return re.sub(r"\s+", " ", unescape(text or "")).strip()


def summary(text, limit):
    text = clean_text(text)
    if len(text) <= limit:
        return text
    return text[:limit].rstrip(" .,") + "."


def is_directory_url(url):
    parsed = urlparse(url)
    host = parsed.netloc.lower().split(":")[0].replace("www.", "")
    return any(host == blocked or host.endswith("." + blocked) for blocked in DIRECTORY_HOSTS)


def allowed_url(url):
    parsed = urlparse(url)
    if parsed.scheme not in {"http", "https"} or not parsed.netloc:
        return False
    if is_directory_url(url):
        return False
    host = parsed.netloc.lower().split(":")[0]
    return not any(host == blocked or host.endswith("." + blocked) for blocked in BLOCKED_HOSTS)


def trusted_source_url(url):
    parsed = urlparse(url)
    if parsed.scheme not in {"http", "https"} or not parsed.netloc:
        return False
    host = parsed.netloc.lower().split(":")[0].replace("www.", "")
    return any(host == source or host.endswith("." + source) for source in TRUSTED_SOURCE_HOSTS)


def usable_source_url(url):
    return allowed_url(url) or trusted_source_url(url)


def same_site(url, base_url):
    return urlparse(url).netloc.lower() == urlparse(base_url).netloc.lower()


def is_document_url(url):
    path = urlparse(url).path.lower()
    return path.endswith((".pdf", ".doc", ".docx", ".xls", ".xlsx", ".ppt", ".pptx", ".zip", ".rar"))


HTTP_SESSION = requests.Session()
HTTP_SESSION.trust_env = False
HTTP_SESSION.headers.update({
    "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36",
    "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
    "Accept-Language": "en-IN,en;q=0.9",
})


def fetch(url, timeout=15):
    try:
        response = HTTP_SESSION.get(url, timeout=timeout, verify=True)
    except requests.exceptions.SSLError:
        response = HTTP_SESSION.get(url, timeout=timeout, verify=False)
    response.raise_for_status()
    return response.text, response.url


def fetch_with_retry(url, retries=3, delay=1.5, timeout=15):
    last_error = None
    for attempt in range(retries):
        try:
            return fetch(url, timeout=timeout)
        except Exception as exc:
            last_error = exc
            log(f"Fetch retry {attempt + 1}/{retries} failed for {url}: {exc}")
            time.sleep(delay * (attempt + 1))
    raise last_error


def search_duckduckgo(query):
    candidates = search_duckduckgo_candidates(query)
    return candidates[0][0] if candidates else ""


def search_duckduckgo_candidates(query):
    url = "https://html.duckduckgo.com/html/?q=" + quote_plus(query)
    html, _ = fetch(url, timeout=6)
    soup = BeautifulSoup(html, "lxml")
    candidates = []
    for link in soup.select("a.result__a"):
        href = link.get("href", "")
        candidate = normalize_search_url(href)
        if allowed_url(candidate):
            candidates.append((candidate, clean_text(link.get_text(" "))))
    return unique_candidates(candidates)


def normalize_search_url(url):
    parsed = urlparse(url)
    params = parse_qs(parsed.query)
    for key in ("uddg", "url", "u"):
        if params.get(key):
            candidate = params[key][0]
            if candidate.startswith("a1"):
                try:
                    encoded = candidate[2:]
                    padding = "=" * (-len(encoded) % 4)
                    decoded = base64.urlsafe_b64decode(encoded + padding).decode("utf-8", "ignore")
                    if decoded.startswith(("http://", "https://")):
                        return decoded
                except Exception:
                    continue
            return candidate
    return url


def search_duckduckgo_lite(query):
    candidates = search_duckduckgo_lite_candidates(query)
    return candidates[0][0] if candidates else ""


def search_duckduckgo_lite_candidates(query):
    url = "https://lite.duckduckgo.com/lite/?q=" + quote_plus(query)
    html, _ = fetch(url, timeout=6)
    soup = BeautifulSoup(html, "lxml")
    candidates = []
    for link in soup.find_all("a", href=True):
        candidate = normalize_search_url(link["href"])
        if allowed_url(candidate):
            candidates.append((candidate, clean_text(link.get_text(" "))))
    return unique_candidates(candidates)


def search_bing(query, college_name, city="", state=""):
    candidates = search_bing_candidates(query)
    return best_candidate(candidates, college_name, city, state)


def search_bing_candidates(query, include_trusted_sources=False):
    url = "https://www.bing.com/search?q=" + quote_plus(query)
    html, _ = fetch(url)
    soup = BeautifulSoup(html, "lxml")
    candidates = []

    for link in soup.select("li.b_algo h2 a, .b_title a, a"):
        href = normalize_search_url(link.get("href", ""))
        href = normalize_search_url(href)
        if allowed_url(href) or (include_trusted_sources and trusted_source_url(href)):
            candidates.append((href, clean_text(link.get_text(" "))))

    return filter_search_candidates(unique_candidates(candidates))


def unique_candidates(candidates):
    seen = set()
    unique = []
    for url, title in candidates:
        key = urlparse(url).netloc.lower().replace("www.", "") + urlparse(url).path.rstrip("/")
        if key in seen:
            continue
        seen.add(key)
        unique.append((url, title))
    return unique


def slug_variants(college_name):
    parenthetical_tokens = [
        re.sub(r"[^a-z0-9]+", "", token.lower())
        for token in re.findall(r"\(([^)]{2,12})\)", college_name)
    ]
    name_without_acronym = re.sub(r"\([^)]{2,12}\)", " ", college_name)
    base_tokens = token_list(name_without_acronym)
    full_tokens = [
        token for token in re.findall(r"[a-z0-9]+", name_without_acronym.lower())
        if len(token) > 1 and token not in {"the", "and", "of", "for", "in", "at", "a", "an"}
    ]
    if not base_tokens and not full_tokens:
        return []

    variants = []
    variants.extend(token for token in parenthetical_tokens if len(token) >= 3)
    joined = "".join(base_tokens)
    dashed = "-".join(base_tokens)
    initials = "".join(token[0] for token in base_tokens if token)
    full_initials = "".join(token[0] for token in full_tokens if token)

    variants.extend([joined, dashed])
    if initials and len(initials) >= 3:
        variants.append(initials)
        variants.append(initials + "college")
    if full_initials and len(full_initials) >= 3:
        variants.append(full_initials)
        variants.append(full_initials + "college")

    # Common official sites drop generic suffix words.
    generic = {"college", "education", "engineering", "technology", "institute"}
    short_tokens = [token for token in base_tokens if token not in generic]
    if short_tokens and short_tokens != base_tokens:
        variants.extend(["".join(short_tokens), "-".join(short_tokens)])
        variants.append("".join(short_tokens) + "college")

    if "women" in full_tokens:
        root_tokens = [token for token in base_tokens if token not in {"women", "girls"}]
        root = "".join(root_tokens)
        if root:
            variants.extend([root + "w", root + "women", root + "collegeforwomen"])
        if "indraprastha" in full_tokens:
            variants.extend(["ipcollege", "ipcw", "ipcollegeforwomen"])

    clean = []
    for variant in variants:
        variant = re.sub(r"[^a-z0-9-]", "", variant.lower()).strip("-")
        if variant and variant not in clean:
            clean.append(variant)
    return clean[:14]


def official_path_variants(college_name):
    name = re.sub(r"\([^)]{2,12}\)", " ", college_name or "")
    tokens = [
        token for token in re.findall(r"[a-z0-9]+", name.lower())
        if len(token) > 1 and token not in {"the", "and", "for", "in", "at", "a", "an"}
    ]
    if not tokens:
        return []

    variants = [
        "-".join(tokens),
        "".join(tokens),
    ]
    compact_name = re.sub(r"[^a-z0-9]+", "-", name.lower()).strip("-")
    if compact_name:
        variants.append(compact_name)

    clean = []
    for variant in variants:
        variant = re.sub(r"[^a-z0-9-]", "", variant).strip("-")
        if variant and variant not in clean:
            clean.append(variant)
    return clean[:6]


def source_slug_variants(college_name):
    parenthetical_tokens = [
        re.sub(r"[^a-z0-9]+", "", token.lower())
        for token in re.findall(r"\(([^)]{2,12})\)", college_name)
    ]
    college_name = re.sub(r"\([^)]{2,12}\)", " ", college_name)
    tokens = [
        token for token in re.findall(r"[a-z0-9]+", college_name.lower())
        if token not in {"the", "and", "of", "for", "in", "at", "a", "an"}
    ]
    all_tokens = [
        token for token in re.findall(r"[a-z0-9]+", college_name.lower())
        if token not in {"the", "and", "for", "in", "at", "a", "an"}
    ]
    variants = []

    for parts in (all_tokens, tokens):
        if not parts:
            continue
        variants.append("-".join(parts))
        variants.append("".join(parts))

    compact = re.sub(r"[^a-z0-9]+", "", college_name.lower())
    if compact:
        variants.append(compact)
    variants.extend(token for token in parenthetical_tokens if len(token) >= 3)

    clean = []
    for variant in variants:
        variant = re.sub(r"[^a-z0-9-]", "", variant).strip("-")
        if variant and variant not in clean:
            clean.append(variant)
    return clean[:10]


def guessed_official_candidates(college_name):
    candidates = []
    slugs = slug_variants(college_name)
    delay_short_acronyms = len(distinctive_name_tokens(college_name)) <= 1
    tlds = [".edu.in", ".ac.in", ".edu", ".org", ".in", ".com"] if delay_short_acronyms else [".ac.in", ".edu.in", ".edu", ".org", ".in", ".com"]
    prefixes = ["https://", "http://", "https://www.", "http://www."]
    slugs = sorted(
        slugs,
        key=lambda slug: (
            delay_short_acronyms and len(slug) <= 3,
            "-" in slug,
            len(slug) > 14,
            len(slug),
        ),
    )

    for slug in slugs:
        for tld in tlds:
            for prefix in prefixes:
                candidates.append((prefix + slug + tld, "guessed official domain"))

    short_slugs = [slug for slug in slugs if len(slug) <= 6 and "-" not in slug]
    root_slugs = [slug for slug in slugs if len(slug) >= 5 and "-" not in slug]
    for short_slug in short_slugs:
        for root_slug in root_slugs:
            if short_slug == root_slug or short_slug in root_slug:
                continue
            for tld in (".edu.in", ".ac.in", ".in", ".org"):
                candidates.append(("https://" + short_slug + "." + root_slug + tld, "guessed official subdomain"))
                candidates.append(("http://" + short_slug + "." + root_slug + tld, "guessed official subdomain"))

    path_slugs = official_path_variants(college_name)
    for slug in slugs:
        for tld in tlds[:2]:
            for prefix in prefixes[:2]:
                for path_slug in path_slugs or [slug]:
                    candidates.append((prefix + slug + tld + "/" + path_slug + "/", "guessed official path"))

    return candidates


def best_candidate(candidates, college_name, city="", state=""):
    if not candidates:
        return ""

    name_tokens = [token for token in re.findall(r"[a-z0-9]+", college_name.lower()) if len(token) > 2]
    location_tokens = [
        token for token in re.findall(r"[a-z0-9]+", (city + " " + state).lower())
        if len(token) > 2
    ]
    ranked = []

    for url, title in candidates:
        parsed = urlparse(url)
        host = parsed.netloc.lower()
        haystack = (host + " " + title.lower()).replace("-", " ")
        score = 0

        if host.endswith(".edu") or host.endswith(".edu.in") or host.endswith(".ac.in"):
            score += 8
        if host.endswith(".org") or host.endswith(".in"):
            score += 3
        if "official" in title.lower() or "official" in url.lower():
            score += 4
        score += sum(1 for token in name_tokens if token in haystack)
        score += sum(2 for token in location_tokens if token in haystack)

        ranked.append((score, url))

    ranked.sort(reverse=True)
    return ranked[0][1] if ranked and ranked[0][0] > 0 else candidates[0][0]


def token_list(value):
    stop_words = {"the", "and", "of", "for", "in", "at", "a", "an", "college", "institute", "university", "education"}
    return [
        token for token in re.findall(r"[a-z0-9]+", value.lower())
        if len(token) > 2 and token not in stop_words
    ]


def normalized_words(value):
    return clean_text(re.sub(r"[^a-z0-9]+", " ", (value or "").lower()))


def normalize_college_search_name(name):
    text = clean_text(re.sub(r"\s*,\s*", " ", (name or "").replace(";", " ")))
    text = re.sub(r"\b([A-Za-z])\s*,\s*([A-Za-z])\b", r"\1\2", text)
    text = re.sub(r"\b([A-Z])\.\s*([A-Z])\.\s*", r"\1\2 ", text)
    text = re.sub(r"\b([A-Z])\.\s*", r"\1 ", text)
    text = re.sub(r"\b([a-z])\.\s*", r"\1 ", text, flags=re.I)
    text = re.sub(r"\s+", " ", text).strip(" ,.-")
    return text


def match_state_name(text):
    text = clean_text(text)
    if not text:
        return ""
    for state_name in sorted(STATES, key=len, reverse=True):
        if text.lower() == state_name.lower():
            return state_name
    return ""


def match_city_name(text):
    text = clean_text(text)
    if not text:
        return ""
    for city_name in sorted(CITIES, key=len, reverse=True):
        if text.lower() == city_name.lower():
            return city_name
    return ""


def parse_college_input(raw_name, entered_city="", entered_state="", entered_pincode=""):
    raw = clean_text(re.sub(r"\s*,\s*", ", ", (raw_name or "").replace(";", ",")))
    display_name = raw
    city = match_city_name(entered_city) or clean_text(entered_city)
    state = match_state_name(entered_state) or clean_text(entered_state)
    pincode = clean_text(entered_pincode)
    working = raw

    pincode_match = re.search(r"\b[1-9][0-9]{5}\b", working)
    if pincode_match:
        pincode = pincode or pincode_match.group(0)
        working = re.sub(r"\b" + re.escape(pincode_match.group(0)) + r"\b", "", working).strip(" ,.-")

    parts = [clean_text(part) for part in raw.split(",") if clean_text(part)]
    if len(parts) >= 2:
        if re.fullmatch(r"[1-9][0-9]{5}", parts[-1]):
            pincode = pincode or parts[-1]
            parts = parts[:-1]
        maybe_state = match_state_name(parts[-1])
        if maybe_state:
            state = state or maybe_state
            parts = parts[:-1]
        if parts:
            maybe_state = match_state_name(parts[-1])
            if maybe_state:
                state = state or maybe_state
                parts = parts[:-1]
        if parts and not city:
            maybe_city = match_city_name(parts[-1])
            if maybe_city:
                city = maybe_city
                parts = parts[:-1]
            elif len(parts[-1]) <= 40 and not re.search(r"\b(college|university|institute|education)\b", parts[-1], re.I):
                city = parts[-1]
                parts = parts[:-1]
        if parts:
            working = clean_text(" ".join(parts))

    for state_name in sorted(STATES, key=len, reverse=True):
        pattern = r",?\s*\b" + re.escape(state_name) + r"\s*$"
        if re.search(pattern, working, re.I):
            state = state or state_name
            working = re.sub(pattern, "", working, flags=re.I).strip(" ,.-")

    for city_name in sorted(CITIES, key=len, reverse=True):
        pattern = r",?\s*\b" + re.escape(city_name) + r"\s*,?"
        if re.search(pattern, working, re.I):
            city = city or city_name
            working = re.sub(pattern, "", working, flags=re.I).strip(" ,.-")

    search_name = normalize_college_search_name(working)
    if not search_name:
        search_name = normalize_college_search_name(display_name)
    display_name = clean_text(working) or display_name

    return {
        "display_name": display_name,
        "search_name": search_name,
        "city": city,
        "state": state,
        "pincode": pincode,
    }


def initials_acronym(name):
    letters = [
        match.group(1).lower()
        for match in re.finditer(r"\b([A-Z])\.", name or "")
    ]
    combined = "".join(letters)
    return combined if len(combined) >= 2 else ""


def is_bad_search_candidate(url, title=""):
    haystack = (url + " " + title).lower()
    return any(part in haystack for part in BAD_SEARCH_URL_PARTS)


def filter_search_candidates(candidates):
    filtered = []
    for url, title in candidates:
        if is_bad_search_candidate(url, title):
            continue
        filtered.append((url, title))
    return filtered


def distinctive_name_tokens(value):
    tokens = [
        token for token in re.findall(r"[a-z0-9]+", (value or "").lower())
        if len(token) > 2 and token not in GENERIC_COLLEGE_NAME_TERMS
    ]
    acronym = initials_acronym(value)
    if acronym and acronym not in tokens:
        tokens.append(acronym)
    return tokens


def college_name_variants(college_name, search_name=""):
    variants = []
    for value in (college_name, search_name):
        normalized = normalized_words(value)
        if normalized and normalized not in variants:
            variants.append(normalized)
    acronym = initials_acronym(college_name)
    if acronym:
        base = normalized_words(search_name or college_name)
        if base:
            variants.append(acronym + " " + base)
            variants.append(base)
    return variants


def parenthetical_acronyms(value):
    return [
        re.sub(r"[^a-z0-9]+", "", token.lower())
        for token in re.findall(r"\(([^)]{2,12})\)", value or "")
        if re.sub(r"[^a-z0-9]+", "", token.lower())
    ]


def college_name_match_stats(haystack, college_name, search_name=""):
    normalized_haystack = normalized_words(haystack)
    name_without_acronym = re.sub(r"\([^)]{2,12}\)", " ", college_name)
    normalized_names = college_name_variants(college_name, search_name or name_without_acronym)
    if normalized_words(name_without_acronym) not in normalized_names:
        normalized_names.append(normalized_words(name_without_acronym))

    distinctive_tokens = distinctive_name_tokens(search_name or name_without_acronym)
    if not distinctive_tokens:
        distinctive_tokens = distinctive_name_tokens(name_without_acronym)

    exact_phrase = any(name and len(name) > 8 and name in normalized_haystack for name in normalized_names)
    token_hits = sum(1 for token in distinctive_tokens if token in normalized_haystack)

    if distinctive_tokens:
        required = required_name_coverage(distinctive_tokens)
        if len(distinctive_tokens) <= 2:
            required = 1
        return token_hits, required, exact_phrase

    # Names such as "I P College Of Education" have no reliable searchable
    # token after generic words are removed. In that case, only an exact page
    # phrase is safe enough; otherwise we would match generic education sites.
    return (1 if exact_phrase else 0), 1, exact_phrase


def required_name_coverage(name_tokens):
    if len(name_tokens) <= 1:
        return 1
    if len(name_tokens) == 2:
        return 2
    return max(2, int(len(name_tokens) * 0.6))


def contains_term(haystack, term):
    if re.fullmatch(r"[a-z0-9 ]+", term):
        return re.search(r"\b" + re.escape(term) + r"\b", haystack) is not None
    return term in haystack


def matched_terms(haystack, terms):
    return [term for term in terms if contains_term(haystack, term)]


def location_terms_except(selected, values):
    selected_tokens = set(token_list(selected))
    terms = []
    for value in values:
        if not value:
            continue
        value_tokens = set(token_list(value))
        if value_tokens and value_tokens != selected_tokens:
            terms.append(value.lower())
    return terms


def location_conflict_hits(haystack, city, state):
    selected_city = city.lower().strip()
    selected_state = state.lower().strip()
    if not selected_city and not selected_state:
        return []
    other_cities = location_terms_except(selected_city, CITIES)
    other_states = location_terms_except(selected_state, STATES)
    hits = []

    for term in other_cities + other_states:
        if term and re.search(r"\b" + re.escape(term) + r"\b", haystack):
            hits.append(term)

    return sorted(set(hits))


def website_validation_score(url, title, html, college_name, city, state, search_name=""):
    parsed = urlparse(url)
    host = parsed.netloc.lower().replace("www.", "")
    soup = BeautifulSoup(html, "lxml")
    page_title = clean_text(soup.title.get_text(" ")) if soup.title else ""
    meta_desc = meta_content(soup, "description", "og:description")

    for tag in soup(["script", "style", "noscript"]):
        tag.decompose()

    text = clean_text(soup.get_text(" "))[:15000]
    haystack = " ".join([host, title, page_title, meta_desc, text]).lower()
    name_tokens = distinctive_name_tokens(college_name)
    city_tokens = token_list(city)
    state_tokens = token_list(state)

    education_hits = len(matched_terms(haystack, EDUCATION_TERMS))
    company_hits = len(matched_terms(haystack, COMPANY_TERMS))
    coaching_hits = len(matched_terms(haystack, COACHING_TERMS))
    hard_coaching_hits = len(matched_terms(haystack, HARD_COACHING_TERMS))
    name_hits, required_hits, exact_name_phrase = college_name_match_stats(haystack, college_name, search_name)
    city_hits = sum(1 for token in city_tokens if token in haystack)
    state_hits = sum(1 for token in state_tokens if token in haystack)
    conflict_hits = location_conflict_hits(haystack, city, state)
    requested_coaching = any(term in college_name.lower() for term in COACHING_TERMS)
    strong_name_match = exact_name_phrase or (name_tokens and name_hits >= max(1, len(name_tokens) - 1))
    acronym_hosts = set(parenthetical_acronyms(college_name))
    host_label = host.split(".")[0]
    if initials_acronym(college_name) and initials_acronym(college_name) in haystack.replace(" ", ""):
        strong_name_match = True
    if city and state and name_hits >= 1:
        strong_name_match = True

    candidate_path = (host + " " + parsed.path.lower())
    if acronym_hosts and not exact_name_phrase and not any(acronym in candidate_path for acronym in acronym_hosts):
        return -100
    if host_label in acronym_hosts and not exact_name_phrase and not host.endswith((".edu.in", ".ac.in", ".edu")):
        return -100
    if "/blog/" in parsed.path.lower() and not exact_name_phrase:
        return -100

    score = 0
    score += name_hits * 8
    score += city_hits * 4
    score += state_hits * 3
    score += min(education_hits, 10) * 3

    if host.endswith((".edu", ".edu.in", ".ac.in")):
        score += 10
    if host.endswith((".org", ".in")):
        score += 3
    if "admission" in haystack or "courses" in haystack:
        score += 4
    if "pvt" in host or "company" in host:
        score -= 12
    if company_hits > 0:
        score -= company_hits * 8
    if hard_coaching_hits > 0 and not requested_coaching:
        score -= coaching_hits * 15
    if (city or state) and conflict_hits and (city_hits + state_hits) == 0:
        return -88
    elif conflict_hits:
        score -= min(len(conflict_hits), 5) * 4

    # A valid college site must identify the college and look educational.
    if name_hits < required_hits:
        return -100
    if hard_coaching_hits > 0 and not requested_coaching:
        return -95
    if education_hits < 2:
        return -80
    if company_hits >= 2 and education_hits < 6:
        return -90
    if (city_tokens or state_tokens) and (city_hits + state_hits) == 0 and not strong_name_match:
        return -85

    return score


def minimum_website_score(college_name):
    tokens = distinctive_name_tokens(college_name)
    if len(tokens) >= 3:
        return 18
    if len(tokens) == 2:
        return 24
    return 30


def should_guess_official_domains(college_name):
    tokens = distinctive_name_tokens(college_name)
    if len(tokens) >= 2 and len(clean_text(college_name)) >= 12:
        return True

    full_tokens = [
        token for token in re.findall(r"[a-z0-9]+", (college_name or "").lower())
        if len(token) > 1 and token not in {"the", "and", "of", "for", "in", "at", "a", "an"}
    ]
    education_token_count = sum(
        1 for token in full_tokens
        if token in {"college", "institute", "institution", "university", "education", "school", "academy"}
    )
    return len(full_tokens) >= 3 and education_token_count >= 1


def is_valid_search_query(query):
    query = clean_text(query)
    if len(query) < 14:
        return False
    words = query.split()
    if any(len(word) == 1 and word.isalpha() for word in words):
        return False
    if sum(1 for word in words if len(word) == 1) >= 2:
        return False
    return True


def build_search_queries(search_name, city="", state="", pincode=""):
    location = " ".join(part for part in [city, state, pincode] if part).strip()
    core = clean_text(search_name)
    if not core:
        return []

    queries = [
        " ".join(part for part in [core, location, "college"] if part),
        " ".join(part for part in [core, location, "official website"] if part),
        " ".join(part for part in [core, location, "college official site India"] if part),
        " ".join(part for part in [core, location, "college admission contact"] if part),
        " ".join(part for part in [core, location, "courses fees admission"] if part),
        " ".join(part for part in [core, "official college website"] if part),
    ]
    tokens = distinctive_name_tokens(core)
    if len(tokens) >= 2:
        queries.append(" ".join(tokens[:6] + ([location] if location else []) + ["college India"]))

    clean = []
    for query in queries:
        query = clean_text(query)
        if is_valid_search_query(query) and query not in clean:
            clean.append(query)
    return clean


def collect_internet_candidates(search_name, city, state, config, pincode=""):
    serp_key = config.get("apis", {}).get("serpapi_key", "").strip()
    use_slow_search = bool(config.get("apis", {}).get("enable_slow_search", False))
    query_variants = build_search_queries(search_name, city, state, pincode)
    candidates = []

    if serp_key:
        for search_query in query_variants:
            url = "https://serpapi.com/search.json?engine=google&q=" + quote_plus(search_query) + "&api_key=" + quote_plus(serp_key)
            try:
                data = HTTP_SESSION.get(url, timeout=25).json()
                for item in data.get("organic_results", []):
                    candidate = item.get("link", "")
                    if allowed_url(candidate) and not is_directory_url(candidate):
                        candidates.append((candidate, item.get("title", "")))
            except Exception as exc:
                log(f"SerpAPI Google search failed: {exc}")

    fallback_searches = [search_bing_candidates]
    if use_slow_search:
        fallback_searches.extend([search_duckduckgo_candidates, search_duckduckgo_lite_candidates])

    for search_query in query_variants:
        for fallback in fallback_searches:
            try:
                candidates.extend(fallback(search_query))
            except Exception as exc:
                log(f"{fallback.__name__} failed: {exc}")

    if use_slow_search and google_search is not None:
        for search_query in query_variants[:3]:
            try:
                for candidate in google_search(search_query, num_results=6):
                    if allowed_url(candidate) and not is_directory_url(candidate):
                        candidates.append((candidate, "google search"))
            except Exception as exc:
                log(f"Google search failed: {exc}")

    return filter_search_candidates(unique_candidates(candidates))


def search_title_matches(title, search_name):
    tokens = distinctive_name_tokens(search_name)
    if not tokens:
        return False
    title_lower = clean_text(title).lower()
    hits = sum(1 for token in tokens if token in title_lower)
    return hits >= min(2, max(1, len(tokens)))


def validate_and_select_website(candidates, college_name, city, state, search_name="", max_checks=35):
    ranked = []
    label = search_name or college_name
    min_score = minimum_website_score(label)
    if city and state:
        min_score = max(10, min_score - 8)

    for candidate, title in unique_candidates(candidates)[:max_checks]:
        if not allowed_url(candidate) or is_directory_url(candidate) or is_bad_search_candidate(candidate, title):
            continue
        try:
            html, final_url = fetch_with_retry(candidate, retries=1, timeout=6)
            if not allowed_url(final_url) or is_directory_url(final_url) or is_bad_search_candidate(final_url, title):
                continue

            if search_title_matches(title, label) and page_has_college_identity(
                html, label, college_name, city, state
            ):
                log(f"Accepted from search title match: {final_url}")
                return final_url

            score = website_validation_score(final_url, title, html, college_name, city, state, search_name)
            log(f"Candidate score={score} url={final_url}")
            if score >= min_score:
                if title.startswith("guessed official") and score >= max(32, min_score + 8):
                    log(f"Accepted guessed official website: {final_url}")
                    return final_url
                if score >= max(55, min_score + 20):
                    log(f"Accepted high-confidence official website: {final_url}")
                    return final_url
                ranked.append((score, final_url))
        except Exception as exc:
            log(f"Candidate validation failed {candidate}: {exc}")

    if not ranked:
        return ""

    ranked.sort(reverse=True)
    return ranked[0][1]


def url_distinctive_hits(url, search_name="", college_name=""):
    path = urlparse(url).path.lower().replace("-", " ").replace("_", " ")
    tokens = distinctive_name_tokens(search_name or college_name)
    return sum(1 for token in tokens if token in path)


def saved_website_is_valid(website, college_name, city, state, search_name=""):
    if not website or not allowed_url(website):
        return False

    try:
        html, final_url = fetch(website)
        score = website_validation_score(final_url, "", html, college_name, city, state, search_name)
        log(f"Saved website score={score} url={final_url}")
        return score >= 18
    except Exception as exc:
        log(f"Saved website validation failed {website}: {exc}")
        return False


def slugify_for_url(name):
    slug = re.sub(r"[^a-z0-9]+", "-", (name or "").lower()).strip("-")
    compact = re.sub(r"[^a-z0-9]+", "", (name or "").lower())
    short = re.sub(r"-(college|of|education|institute|university).*$", "", slug).strip("-")
    variants = []
    for value in (slug, compact, short):
        if value and value not in variants:
            variants.append(value)
    return variants


def page_has_college_identity(html, search_name, college_name, city="", state=""):
    soup = BeautifulSoup(html, "lxml")
    title = clean_text(soup.title.get_text(" ")) if soup.title else ""
    text = clean_text(soup.get_text(" "))[:12000].lower()
    haystack = (title + " " + text).lower()
    tokens = distinctive_name_tokens(search_name or college_name)
    if not tokens:
        return False
    name_hits = sum(1 for token in tokens if token in haystack)
    if name_hits < 1:
        return False
    education_hits = len(matched_terms(haystack, EDUCATION_TERMS))
    if education_hits < 1:
        return False
    if city and city.lower() not in haystack and state and state.lower() not in haystack:
        if name_hits < 2:
            return False
    return True


def discover_college_pages(parsed, config):
    """Find the official college website via search, then scrape that site only."""
    college_name = parsed["display_name"]
    search_name = parsed["search_name"]
    city = parsed["city"]
    state = parsed["state"]
    pincode = parsed.get("pincode", "")

    log(f"Google/Bing search for official website: {search_name} | {city} | {state} | {pincode}")
    website = find_website(college_name, city, state, config, search_name, pincode)
    if website and not is_directory_url(website):
        log(f"Official website selected: {website}")
        pages = collect_pages(website, city, state)
        return pages, website, "official"

    raise RuntimeError(
        "Official college website not found. Scraper is configured to use Google/Bing discovery and the college website only."
    )


def find_website(college_name, city, state, config, search_name="", pincode=""):
    search_label = search_name or college_name
    log(f"Google/Bing search for official site: {search_label} | city={city} | state={state} | pincode={pincode}")

    search_candidates = filter_search_candidates(
        unique_candidates(collect_internet_candidates(search_label, city, state, config, pincode))
    )
    selected = validate_and_select_website(
        search_candidates, college_name, city, state, search_label, max_checks=30
    )
    if selected:
        return selected

    if should_guess_official_domains(search_label):
        guessed = filter_search_candidates(guessed_official_candidates(search_label))
        selected = validate_and_select_website(
            guessed, college_name, city, state, search_label, max_checks=30
        )
        if selected:
            return selected

    raise RuntimeError(
        "Official college website not found via Google/Bing. "
        "Use format: College Name, City, State"
    )


def page_location_score(html, url, city, state):
    soup = BeautifulSoup(html, "lxml")
    title = clean_text(soup.title.get_text(" ")) if soup.title else ""
    for tag in soup(["script", "style", "noscript"]):
        tag.decompose()
    text = clean_text(soup.get_text(" "))[:12000]
    haystack = " ".join([url, title, text]).lower()
    city_hits = sum(1 for token in token_list(city) if token in haystack)
    state_hits = sum(1 for token in token_list(state) if token in haystack)
    conflict_hits = location_conflict_hits(haystack, city, state)
    conflicts = location_conflict_hits(haystack, city, state)

    score = city_hits * 5 + state_hits * 3
    if conflicts and (city_hits + state_hits) == 0:
        score -= 20
    elif conflicts:
        score -= min(len(conflicts), 5) * 3

    return score


def collect_pages(website, city="", state=""):
    html, final_url = fetch_with_retry(website)
    pages = [(final_url, html)]
    soup = BeautifulSoup(html, "lxml")
    allow_trusted = trusted_source_url(final_url)
    wanted = (
        "contact", "about", "admission", "course", "program", "programme",
        "fee", "fees", "facility", "facilities", "infrastructure",
        "department", "academics", "college", "profile", "prospectus",
        "brochure", "hostel", "scholarship", "placement",
    )
    seen = {final_url.rstrip("/")}
    weighted_links = []

    for link in soup.find_all("a", href=True):
        text = clean_text(link.get_text(" ")).lower()
        href = link["href"].lower()
        candidate = urljoin(final_url, link["href"])
        if is_document_url(candidate):
            continue
        if not same_site(candidate, final_url):
            continue
        if allow_trusted and not any(word in candidate.lower() for word in ("course", "fee", "admission", "placement", "contact", "review", "college")):
            continue
        score = sum(4 for word in wanted if word in text or word in href)
        if score <= 0:
            continue
        if "fee" in text or "fee" in href:
            score += 8
        if "course" in text or "academics" in text or "program" in href:
            score += 6
        if "admission" in text or "apply" in text:
            score += 5
        weighted_links.append((score, candidate))

    attempted = 0
    for _, candidate in sorted(weighted_links, reverse=True):
        key = candidate.rstrip("/")
        if key in seen or not usable_source_url(candidate):
            continue
        seen.add(key)
        attempted += 1
        try:
            child_html, child_url = fetch_with_retry(candidate, retries=1, timeout=8)
            page_score = page_location_score(child_html, child_url, city, state)
            if page_score < -5:
                log(f"Skipped location-conflicting page score={page_score} url={child_url}")
                continue
            pages.append((child_url, child_html))
        except Exception as exc:
            log(f"Skipped inner page {candidate}: {exc}")
        if len(pages) >= 10:
            break
        if attempted >= 12:
            break

    return pages


def classify_link(text, href):
    haystack = (text + " " + href).lower()
    for category, keywords in LINK_KEYWORDS.items():
        if any(keyword in haystack for keyword in keywords):
            return category
    return ""


def extract_social_and_links(pages):
    social = {"facebook": "", "instagram": "", "twitter": "", "linkedin": ""}
    important = {}
    source_pages = []

    for page_url, html in pages:
        source_pages.append(page_url)
        soup = BeautifulSoup(html, "lxml")
        for link in soup.find_all("a", href=True):
            href = urljoin(page_url, link["href"])
            text = clean_text(link.get_text(" "))
            host = urlparse(href).netloc.lower()

            if "facebook.com" in host and not social["facebook"]:
                social["facebook"] = href
            elif "instagram.com" in host and not social["instagram"]:
                social["instagram"] = href
            elif ("twitter.com" in host or "x.com" in host) and not social["twitter"]:
                social["twitter"] = href
            elif "linkedin.com" in host and not social["linkedin"]:
                social["linkedin"] = href

            if same_site(href, page_url):
                category = classify_link(text, href)
                if category and category not in important:
                    important[category] = {"title": text or category.title(), "url": href}

    return social, important, source_pages


def nearby_snippets(text, keywords, limit=6, radius=160):
    snippets = []
    lowered = text.lower()

    for keyword in keywords:
        for match in re.finditer(re.escape(keyword.lower()), lowered):
            start = max(0, match.start() - radius)
            end = min(len(text), match.end() + radius)
            snippet = clean_text(text[start:end]).strip(" .,:;-")
            if len(snippet) > 40 and snippet not in snippets:
                snippets.append(snippet)
            if len(snippets) >= limit:
                return snippets

    return snippets


def extract_pdf_links(pages):
    links = []
    for page_url, html in pages:
        soup = BeautifulSoup(html, "lxml")
        for link in soup.find_all("a", href=True):
            href = urljoin(page_url, link["href"])
            text = clean_text(link.get_text(" "))
            if ".pdf" in href.lower() or any(word in (href + " " + text).lower() for word in ("prospectus", "brochure", "fee structure")):
                links.append({"title": text or "Download", "url": href})
            if len(links) >= 12:
                return links
    return links


def extract_contact_block(text):
    snippets = nearby_snippets(text, ["contact", "phone", "email", "address", "admission office"], limit=4, radius=220)
    return "\n".join(snippets[:4])


def extract_address(text):
    patterns = [
        r"(?:address|location)\s*:?\s*\|?\s*(.{25,360}?)(?:phone|fax|email|website|courses|course|admission|facilities|$)",
        r"(?:address)\s+(.{25,360}?)(?:phone|fax|email|website|courses|course|admission|facilities|$)",
    ]
    for pattern in patterns:
        match = re.search(pattern, text, re.I)
        if match:
            value = clean_text(match.group(1))
            value = re.sub(r"\b(N/A|Not Available)\b", "", value, flags=re.I)
            return clean_text(value)
    return ""


def extract_location_from_text(text):
    state = next((state for state in STATES if re.search(r"\b" + re.escape(state) + r"\b", text, re.I)), "")
    city = next((city for city in CITIES if re.search(r"\b" + re.escape(city) + r"\b", text, re.I)), "")
    return city, state


def extract_admission_summary(text):
    snippets = nearby_snippets(text, ["admission", "eligibility", "apply", "entrance", "selection"], limit=5, radius=180)
    return "\n".join(snippets[:5])


def meta_content(soup, *names):
    for name in names:
        node = soup.find("meta", attrs={"name": re.compile("^" + re.escape(name) + "$", re.I)})
        if node and node.get("content"):
            return clean_text(node["content"])
        node = soup.find("meta", attrs={"property": re.compile("^" + re.escape(name) + "$", re.I)})
        if node and node.get("content"):
            return clean_text(node["content"])
    return ""


def image_candidate_score(tag):
    raw = " ".join([
        tag.get("src", ""),
        tag.get("alt", ""),
        " ".join(tag.get("class", [])) if isinstance(tag.get("class"), list) else str(tag.get("class", "")),
        tag.get("id", ""),
    ]).lower()
    score = 0
    if "logo" in raw:
        score += 10
    if "brand" in raw or "header" in raw:
        score += 4
    if "college" in raw or "university" in raw:
        score += 3
    if any(raw.endswith(ext) for ext in (".svg", ".png", ".jpg", ".jpeg", ".webp")):
        score += 1
    if "sprite" in raw or "loader" in raw or "blank" in raw:
        score -= 6
    return score


def extract_fee(text):
    patterns = [
        r"(?:hostel\s+fees?|tuition\s+fees?|annual\s+fees?|course\s+fees?|fees?)\s*:?\s*(?:rs\.?|inr|₹)?\s*[0-9][0-9,.\s]*(?:/-|per year|yearly|annual|semester)?",
        r"(?:₹|rs\.?|inr)\s*[0-9][0-9,.\s]*(?:/-|per year|yearly|annual|semester)?",
    ]
    values = []
    for pattern in patterns:
        for match in re.findall(pattern, text, re.I):
            value = clean_text(match)
            normalized = re.sub(r"^(?:hostel\s+fees?|tuition\s+fees?|annual\s+fees?|course\s+fees?|fees?)\s*:?\s*", "", value, flags=re.I)
            normalized = clean_text(normalized)
            amount_match = re.search(r"[0-9][0-9,.\s]*", normalized)
            amount_number = int(re.sub(r"[^0-9]+", "", amount_match.group(0))) if amount_match else 0
            amount_float = float(re.sub(r"[^0-9.]+", "", amount_match.group(0)) or 0) if amount_match else 0
            if amount_float < 500 or amount_number < 500 or amount_number > 5000000:
                continue
            if 1900 <= amount_number <= 2099:
                continue
            comparable = re.sub(r"[^0-9]+", "", amount_match.group(0)) if amount_match else re.sub(r"[^0-9a-z]+", "", normalized.lower())
            existing = {re.sub(r"[^0-9a-z]+", "", item.lower()) for item in values}
            existing_amounts = set()
            for item in values:
                existing_match = re.search(r"[0-9][0-9,.\s]*", item)
                if existing_match:
                    existing_amounts.add(re.sub(r"[^0-9]+", "", existing_match.group(0)))
            if normalized and comparable not in existing and comparable not in existing_amounts:
                values.append(normalized)
            elif value and re.sub(r"[^0-9a-z]+", "", value.lower()) not in existing and comparable not in existing_amounts:
                values.append(value)
            if len(values) >= 5:
                break
    return ", ".join(values[:5])


def extract_fee(text):
    money = r"(?:rs\.?|inr|₹)?\s*[0-9][0-9,]*(?:\.[0-9]+)?\s*(?:lakh|lac|lakhs|lacs|k|thousand)?"
    suffix = r"(?:\s*(?:/-|per\s+year|yearly|annual|annum|per\s+annum|semester|per\s+semester|monthly|one\s+time))?"
    patterns = [
        rf"(?:hostel\s+fees?|tuition\s+fees?|annual\s+fees?|semester\s+fees?|course\s+fees?|fees?)\s*(?:structure)?\s*:?\s*{money}{suffix}",
        rf"{money}{suffix}\s*(?:hostel\s+fees?|tuition\s+fees?|annual\s+fees?|semester\s+fees?|course\s+fees?|fees?)",
        rf"(?:rs\.?|inr|₹)\s*[0-9][0-9,]*(?:\.[0-9]+)?\s*(?:lakh|lac|lakhs|lacs|k|thousand)?{suffix}",
    ]
    values = []
    for pattern in patterns:
        for match in re.findall(pattern, text, re.I):
            value = clean_text(match)
            normalized = re.sub(
                r"^(?:hostel\s+fees?|tuition\s+fees?|annual\s+fees?|semester\s+fees?|course\s+fees?|fees?)\s*(?:structure)?\s*:?\s*",
                "",
                value,
                flags=re.I,
            )
            normalized = clean_text(normalized)
            amount_match = re.search(r"[0-9][0-9,.\s]*", normalized)
            if not amount_match:
                continue
            amount_number = int(re.sub(r"[^0-9]+", "", amount_match.group(0)))
            amount_float = float(re.sub(r"[^0-9.]+", "", amount_match.group(0)) or 0)
            lowered = normalized.lower()
            multiplier = 1
            if any(unit in lowered for unit in ("lakh", "lac")):
                multiplier = 100000
            elif re.search(r"\d\s*k\b", lowered):
                multiplier = 1000
            comparable_amount = amount_float * multiplier
            if comparable_amount < 500 or comparable_amount > 5000000:
                continue
            if 1900 <= amount_number <= 2099:
                continue
            if re.search(r"\b\d{1,2}[-/]\d{1,2}[-/]\d{2,4}\b", normalized):
                continue
            comparable = re.sub(r"[^0-9]+", "", amount_match.group(0))
            existing_amounts = set()
            for item in values:
                existing_match = re.search(r"[0-9][0-9,.\s]*", item)
                if existing_match:
                    existing_amounts.add(re.sub(r"[^0-9]+", "", existing_match.group(0)))
            if comparable not in existing_amounts:
                values.append(normalized or value)
            if len(values) >= 5:
                break
    return ", ".join(values[:5])


def extract_fee_reference(pdf_links, important_links):
    references = []

    for link in pdf_links:
        if not isinstance(link, dict):
            continue
        title = clean_text(link.get("title") or "Fee / Prospectus")
        url = clean_text(link.get("url") or "")
        haystack = (title + " " + url).lower()
        if url and any(term in haystack for term in FEE_TERMS):
            references.append((title, url))

    for category, link in (important_links or {}).items():
        if not isinstance(link, dict):
            continue
        title = clean_text(link.get("title") or str(category).title())
        url = clean_text(link.get("url") or "")
        haystack = (str(category) + " " + title + " " + url).lower()
        if url and any(term in haystack for term in FEE_TERMS):
            references.append((title, url))

    clean = []
    seen = set()
    for title, url in references:
        if url in seen:
            continue
        seen.add(url)
        clean.append(f"{title}: {url}")

    if not clean:
        return ""
    return "Fee details available in official source: " + " | ".join(clean[:3])


def extract_topic_reference(pdf_links, important_links, label, terms):
    references = []

    for category, link in (important_links or {}).items():
        if not isinstance(link, dict):
            continue
        title = clean_text(link.get("title") or str(category).title())
        url = clean_text(link.get("url") or "")
        haystack = (str(category) + " " + title + " " + url).lower()
        if url and any(term in haystack for term in terms):
            references.append((title, url))

    for link in pdf_links:
        if not isinstance(link, dict):
            continue
        title = clean_text(link.get("title") or label)
        url = clean_text(link.get("url") or "")
        haystack = (title + " " + url).lower()
        if url and any(term in haystack for term in terms):
            references.append((title, url))

    clean = []
    seen = set()
    for title, url in references:
        if url in seen:
            continue
        seen.add(url)
        clean.append(f"{title}: {url}")

    if not clean:
        return ""
    return f"{label} details available in official source: " + " | ".join(clean[:3])


def fee_focused_text(text):
    snippets = nearby_snippets(
        text,
        ["fee structure", "tuition fee", "course fee", "fees", "hostel fee"],
        limit=8,
        radius=360,
    )
    return " ".join(snippets)


def extract_facilities(text):
    found = []
    for facility in FACILITY_WORDS:
        if re.search(r"\b" + re.escape(facility) + r"\b", text, re.I):
            found.append(facility)
    return ", ".join(dict.fromkeys(found))


def extract_course_names(text):
    found = []
    for course in COURSES:
        if re.search(r"\b" + re.escape(course) + r"\b", text, re.I):
            found.append(course)

    degree_patterns = [
        r"\bBachelor of [A-Za-z &]{3,60}",
        r"\bMaster of [A-Za-z &]{3,60}",
        r"\bDiploma in [A-Za-z &]{3,60}",
        r"\bPost Graduate Diploma in [A-Za-z &]{3,60}",
    ]
    for pattern in degree_patterns:
        for match in re.findall(pattern, text, re.I):
            value = clean_text(match).rstrip(" .,:;")
            if len(value) <= 80:
                found.append(value)

    unique = []
    seen = set()
    for value in found:
        key = re.sub(r"[^a-z0-9]+", "", value.lower())
        if key not in seen:
            unique.append(value)
            seen.add(key)

    return ", ".join(unique)[:2000]


def course_focused_text(text):
    snippets = nearby_snippets(
        text,
        ["courses offered", "course / degree", "ug courses", "pg courses", "degree name", "programmes offered"],
        limit=8,
        radius=520,
    )
    return " ".join(snippets)


def best_description(meta_description, page_title, text, college_name):
    if meta_description:
        return meta_description

    sentences = re.split(r"(?<=[.!?])\s+", text)
    scored = []
    first_token = (college_name.lower().split() or [""])[0]
    for sentence in sentences:
        clean = clean_text(sentence)
        if len(clean) < 80 or len(clean) > 450:
            continue
        low = clean.lower()
        score = 0
        if first_token and first_token in low:
            score += 4
        for word in ("college", "institute", "university", "established", "affiliated", "approved", "courses", "campus"):
            if word in low:
                score += 1
        scored.append((score, clean))

    if scored:
        return sorted(scored, reverse=True)[0][1]

    return page_title or summary(text, 250)


def extract_details(pages, college_name):
    joined_text = []
    images = []
    logo = ""
    meta_description = ""
    page_title = ""
    logo_candidates = []

    for url, html in pages:
        soup = BeautifulSoup(html, "lxml")
        if not meta_description:
            meta_description = meta_content(soup, "description", "og:description")
        if not page_title and soup.title:
            page_title = clean_text(soup.title.get_text(" "))

        icon = soup.find("link", rel=re.compile("icon", re.I))
        if icon and icon.get("href"):
            logo_candidates.append((5, urljoin(url, icon["href"])))

        for image in soup.find_all("img", src=True):
            src = urljoin(url, image["src"])
            score = image_candidate_score(image)
            if score > 0:
                logo_candidates.append((score, src))

        for tag in soup(["script", "style", "noscript"]):
            tag.decompose()
        text = clean_text(soup.get_text(" "))
        joined_text.append(text)

        for image in soup.find_all("img", src=True):
            src = urljoin(url, image["src"])
            if src not in images and allowed_url(src) and len(images) < 20:
                images.append(src)

    if logo_candidates:
        logo = sorted(logo_candidates, key=lambda item: item[0], reverse=True)[0][1]

    text = clean_text(" ".join(joined_text))
    social, important_links, source_pages = extract_social_and_links(pages)
    pdf_links = extract_pdf_links(pages)
    base_host = urlparse(pages[0][0]).netloc.lower().replace("www.", "") if pages else ""
    source_is_trusted_directory = trusted_source_url(pages[0][0]) if pages else False
    base_parts = base_host.split(".")
    base_domain = ".".join(base_parts[-3:]) if len(base_parts) >= 3 and base_parts[-2] in {"ac", "edu", "gov"} else ".".join(base_parts[-2:])
    raw_emails = sorted(set(re.findall(r"[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}", text, re.I)))
    email_candidates = []
    for email in raw_emails:
        local, _, domain = email.partition("@")
        domain = domain.lower()
        if len(local) < 2:
            continue
        if domain.endswith((".png", ".jpg", ".jpeg", ".gif", ".svg", ".webp")):
            continue
        if source_is_trusted_directory and any(domain == source or domain.endswith("." + source) for source in TRUSTED_SOURCE_HOSTS):
            continue
        if source_is_trusted_directory and any(term in (local + " " + domain) for term in SOURCE_BRAND_TERMS):
            continue
        score = 0
        if base_domain and domain.endswith(base_domain):
            score += 20
        if domain.endswith((".ac.in", ".edu.in", ".edu")):
            score += 8
        if local.lower() in {"info", "principal", "admission", "admissions", "office", "contact"}:
            score += 5
        if domain in {"gmail.com", "yahoo.com", "hotmail.com", "outlook.com"}:
            score -= 3
        email_candidates.append((score, email))
    emails = [email for _, email in sorted(email_candidates, reverse=True)]
    contact_block = extract_contact_block(text)
    phone_source = contact_block if source_is_trusted_directory else text
    phones = sorted(set(re.findall(r"(?:\+91[\s-]?)?[6-9]\d{9}|\(?0\d{2,5}\)?[\s-]?\d{6,8}", phone_source)))
    focused_courses = course_focused_text(text)
    found_courses = extract_course_names(focused_courses) or extract_course_names(text)
    focused_fees = fee_focused_text(text)
    found_fees = (
        extract_fee(focused_fees)
        or extract_fee(text)
        or extract_fee_reference(pdf_links, important_links)
    )

    address = extract_address(text)
    location_text = address or contact_block or text

    city, state = extract_location_from_text(location_text)
    pincode_match = re.search(r"\b[1-9][0-9]{5}\b", location_text) or re.search(r"\b[1-9][0-9]{5}\b", text)
    admission_summary = extract_admission_summary(text) or extract_topic_reference(
        pdf_links,
        important_links,
        "Admission",
        ("admission", "apply", "registration", "prospectus", "brochure"),
    )
    placement_info = extract_placement_info(text) or extract_topic_reference(
        pdf_links,
        important_links,
        "Placement",
        ("placement", "career", "training", "recruiter"),
    )
    about_college = best_description(meta_description, page_title, text, college_name)

    return {
        "text": text,
        "email": emails[0] if emails else "",
        "emails": ", ".join(emails[:8]),
        "phone": clean_text(phones[0]) if phones else "",
        "phones": ", ".join(clean_text(phone) for phone in phones[:8]),
        "address": address,
        "city": city,
        "state": state,
        "pincode": pincode_match.group(0) if pincode_match else "",
        "courses": found_courses,
        "fees": found_fees,
        "facilities": extract_facilities(text),
        "admission_process": admission_summary,
        "placement_info": placement_info,
        "about_college": about_college,
        "fee_snippets": "\n".join(nearby_snippets(text, ["fee", "fees", "tuition", "hostel fee"], limit=6, radius=180)),
        "course_snippets": "\n".join(nearby_snippets(text, ["course", "courses", "programme", "program", "department"], limit=6, radius=180)),
        "admission_summary": admission_summary,
        "contact_block": contact_block,
        "logo": logo,
        "images": json.dumps(images, ensure_ascii=False),
        "important_links": json.dumps(important_links, ensure_ascii=False),
        "pdf_links": json.dumps(pdf_links, ensure_ascii=False),
        "source_pages": json.dumps(source_pages, ensure_ascii=False),
        "social": social,
        "meta_description": meta_description,
        "page_title": page_title,
        "best_description": best_description(meta_description, page_title, text, college_name),
    }


def validate_scraped_details(details, college_name, city, state, search_name=""):
    haystack = " ".join([
        details.get("text", ""),
        details.get("page_title", ""),
        details.get("meta_description", ""),
    ]).lower()
    name_tokens = distinctive_name_tokens(search_name or college_name)
    name_hits, required_hits, exact_name_phrase = college_name_match_stats(haystack, college_name, search_name)
    city_hits = sum(1 for token in token_list(city) if token in haystack)
    state_hits = sum(1 for token in token_list(state) if token in haystack)
    coaching_hits = len(matched_terms(haystack, COACHING_TERMS))
    hard_coaching_hits = len(matched_terms(haystack, HARD_COACHING_TERMS))
    company_hits = len(matched_terms(haystack, COMPANY_TERMS))
    requested_coaching = any(term in college_name.lower() for term in COACHING_TERMS)
    strong_name_match = exact_name_phrase or (name_tokens and name_hits >= len(name_tokens))
    hard_terms_found = matched_terms(haystack, HARD_COACHING_TERMS)
    company_terms_found = matched_terms(haystack, COMPANY_TERMS)
    conflict_hits = location_conflict_hits(haystack, city, state)

    if name_hits < required_hits:
        raise RuntimeError("Scraped website rejected: page content does not match the requested college name.")
    if hard_coaching_hits > 0 and not requested_coaching and name_hits < required_hits:
        raise RuntimeError("Scraped website rejected: result appears to be IAS/coaching content (" + ", ".join(hard_terms_found[:5]) + "), not the requested college.")
    education_hits = len(matched_terms(haystack, EDUCATION_TERMS))

    if company_hits >= 3 and not strong_name_match and education_hits < 6:
        raise RuntimeError("Scraped website rejected: result appears to be a company/business website (" + ", ".join(company_terms_found[:5]) + ").")
    if (city or state) and conflict_hits and (city_hits + state_hits) == 0:
        raise RuntimeError("Scraped website rejected: page appears to belong to another location (" + ", ".join(conflict_hits[:5]) + "), not selected city/state.")
    if (city or state) and (city_hits + state_hits) == 0 and not strong_name_match:
        raise RuntimeError("Scraped website rejected: page content does not match selected city/state.")
    if not city and not state:
        return


def geocode(college_name, address, city, state, config):
    query = " ".join(part for part in [college_name, address, city, state] if part)
    if not query:
        return None, None

    google_key = config.get("apis", {}).get("google_geocoding_key", "").strip()
    if google_key:
        url = "https://maps.googleapis.com/maps/api/geocode/json"
        data = HTTP_SESSION.get(url, params={"address": query, "key": google_key}, timeout=20).json()
        results = data.get("results", [])
        if results:
            loc = results[0]["geometry"]["location"]
            return loc.get("lat"), loc.get("lng")

    try:
        data = HTTP_SESSION.get(
            "https://nominatim.openstreetmap.org/search",
            params={"q": query, "format": "json", "limit": 1},
            headers={"User-Agent": "TopCollegesScraper/1.0"},
            timeout=20,
        ).json()
        if data:
            return float(data[0]["lat"]), float(data[0]["lon"])
    except Exception as exc:
        log(f"Geocode failed: {exc}")

    return None, None


def extract_placement_info(text):
    snippets = nearby_snippets(
        text,
        ["placement", "recruit", "recruiter", "package", "companies visited", "highest package", "average package"],
        limit=5,
        radius=220,
    )
    return "\n".join(snippets[:5])


def has_meaningful_value(value):
    if value is None:
        return False
    if isinstance(value, (int, float)):
        return value != 0
    text = clean_text(str(value))
    if not text:
        return False
    lowered = text.lower()
    blocked = {
        "n/a",
        "na",
        "not found",
        "not available",
        "pending",
        "tbd",
        "unknown",
        "null",
        "none",
        "-",
        "--",
    }
    if lowered in blocked:
        return False
    if re.fullmatch(r"[0-9.,\s/-]+", text) and len(re.sub(r"[^0-9]", "", text)) < 4:
        return False
    return True


def build_seo_slug(college_name, city="", state=""):
    parts = [college_name]
    if city:
        parts.append(city)
    elif state:
        parts.append(state)
    slug = re.sub(r"[^a-z0-9]+", "-", " ".join(part for part in parts if part).lower()).strip("-")
    return slug[:255]


def seo_content(college_name, website, details):
    place = ", ".join(part for part in [details["city"], details["state"]] if part)
    title_place = f" in {place}" if place else ""
    title = (college_name + title_place + " Admission 2026 Fees Courses Placement")[:255]
    description = ""
    if has_meaningful_value(details.get("meta_description")):
        description = summary(details["meta_description"], 160)
    elif has_meaningful_value(details.get("about_college")):
        description = summary(details["about_college"], 160)

    keywords = []
    for item in [
        college_name,
        college_name + " admission",
        college_name + " courses",
        college_name + " fees",
        college_name + " placement",
        place,
    ]:
        if item and item not in keywords:
            keywords.append(item)

    content_parts = []
    if has_meaningful_value(details.get("about_college")):
        content_parts.append(details["about_college"])
    if has_meaningful_value(details.get("courses")):
        content_parts.append("Courses: " + details["courses"])
    if has_meaningful_value(details.get("fees")):
        content_parts.append("Fees: " + details["fees"])
    if has_meaningful_value(details.get("facilities")):
        content_parts.append("Facilities: " + details["facilities"])
    if has_meaningful_value(details.get("admission_process")):
        content_parts.append("Admission: " + details["admission_process"])
    if has_meaningful_value(details.get("placement_info")):
        content_parts.append("Placement: " + details["placement_info"])
    if has_meaningful_value(details.get("address")):
        content_parts.append("Address: " + details["address"])
    elif place:
        content_parts.append("Location: " + place)
    if has_meaningful_value(website):
        content_parts.append("Website: " + website)

    return title, description, ", ".join(keywords), "\n\n".join(content_parts)


def build_evidence_sections(details):
    sections = []
    mapping = [
        ("Overview", summary(details.get("text", ""), 1800)),
        ("Contact Details", details.get("contact_block", "")),
        ("All Emails", details.get("emails", "")),
        ("All Phones", details.get("phones", "")),
        ("Courses Evidence", details.get("course_snippets", "")),
        ("Fee Evidence", details.get("fee_snippets", "")),
        ("Admission Evidence", details.get("admission_summary", "")),
        ("Placement Evidence", details.get("placement_info", "")),
    ]
    for title, value in mapping:
        if has_meaningful_value(value):
            sections.append(f"{title}:\n{value}")
    return "\n\n".join(sections)[:9000]


def build_update_payload(
    college_name,
    website,
    details,
    seo_title,
    seo_description,
    seo_keywords,
    seo_body,
    seo_slug,
    forced_city="",
    forced_state="",
    forced_pincode="",
):
    payload = {}

    def put(key, value):
        if has_meaningful_value(value):
            payload[key] = value

    put("college_name", college_name)
    put("clg_name", college_name)
    if has_meaningful_value(website):
        payload["website"] = website
    if has_meaningful_value(forced_city):
        payload["city"] = forced_city
    if has_meaningful_value(forced_state):
        payload["state"] = forced_state
    if has_meaningful_value(forced_pincode):
        payload["pincode"] = forced_pincode
    put("email", details.get("email"))
    put("phone", details.get("phone"))
    put("address", details.get("address"))
    put("city", details.get("city"))
    put("state", details.get("state"))
    put("pincode", details.get("pincode"))
    put("courses", details.get("courses"))
    put("course_name", details.get("courses"))
    put("fees", details.get("fees"))
    put("facilities", details.get("facilities"))
    put("admission_process", details.get("admission_process"))
    put("placement_info", details.get("placement_info"))
    put("about_college", details.get("about_college"))

    if details.get("latitude") is not None and details.get("longitude") is not None:
        payload["latitude"] = details["latitude"]
        payload["longitude"] = details["longitude"]

    put("logo", details.get("logo"))
    put("images", details.get("images"))
    put("important_links", details.get("important_links"))
    put("source_pages", details.get("source_pages"))
    put("facebook", details.get("facebook"))
    put("instagram", details.get("instagram"))
    put("twitter", details.get("twitter"))
    put("linkedin", details.get("linkedin"))
    put("photos", details.get("images"))

    put("short_description", details.get("short_description"))
    put("description", details.get("description"))
    put("long_description", details.get("long_description"))
    put("title", seo_title)
    put("heading", college_name)
    put("seo_title", seo_title)
    put("seo_description", seo_description)
    put("seo_keywords", seo_keywords)
    put("seo_content", seo_body)
    put("slug", seo_slug)

    return payload


def save_college_updates(cursor, college_id, payload):
    if not payload:
        raise RuntimeError("No verified college data found to save.")

    payload["scrape_status"] = "completed"
    payload["scrape_error"] = None
    payload["scrape_started_at"] = None
    set_parts = []
    values = []
    for column, value in payload.items():
        if column == "scraped_at":
            continue
        set_parts.append(f"{column}=%s")
        values.append(value)

    set_parts.append("scraped_at=NOW()")
    sql = "UPDATE colleges SET " + ", ".join(set_parts) + " WHERE id=%s"
    values.append(college_id)
    execute_with_deadlock_retry(cursor, sql, tuple(values))


def parse_cli_args():
    if len(sys.argv) < 2:
        raise SystemExit("Usage: scraper.py COLLEGE_ID [COLLEGE_NAME]")

    college_id = int(sys.argv[1])
    college_name = clean_text(sys.argv[2]) if len(sys.argv) > 2 else ""

    if college_id <= 0:
        raise SystemExit("Invalid college id")

    if college_name and len(college_name) > 255:
        raise SystemExit("College name is too long")

    return college_id, college_name


def mark_status(cursor, college_id, status, error=""):
    execute_with_deadlock_retry(
        cursor,
        "UPDATE colleges SET scrape_status=%s, scrape_error=%s, scrape_started_at=NULL WHERE id=%s",
        (status, error or None, college_id),
    )


def acquire_scraper_lock(cursor, college_id):
    execute_with_deadlock_retry(
        cursor,
        """
        UPDATE colleges
           SET scrape_status='running',
               scrape_error=NULL,
               scrape_started_at=NOW()
         WHERE id=%s
           AND (
               scrape_status <> 'running'
               OR scrape_started_at IS NULL
               OR scrape_started_at < (NOW() - INTERVAL 20 MINUTE)
           )
        """,
        (college_id,),
    )
    return cursor.rowcount == 1


def college_exists(cursor, college_id):
    cursor.execute("SELECT scrape_status FROM colleges WHERE id=%s LIMIT 1", (college_id,))
    return cursor.fetchone()


def main():
    college_id, cli_college_name = parse_cli_args()
    config = load_config()
    conn = db_connect(config)

    try:
        with conn.cursor() as cursor:
            ensure_runtime_schema(cursor)
            conn.commit()

            if not acquire_scraper_lock(cursor, college_id):
                current = college_exists(cursor, college_id)
                conn.commit()
                if current and current.get("scrape_status") == "running":
                    log(f"Skipped college_id={college_id}: scraper already running")
                    print("Scraper already running")
                    return
                raise RuntimeError(f"College id {college_id} not found in database.")
            conn.commit()

            cursor.execute(
                "SELECT id, COALESCE(NULLIF(college_name,''), NULLIF(clg_name,'')) AS name, website, city, state, pincode, slug "
                "FROM colleges WHERE id=%s LIMIT 1",
                (college_id,),
            )
            college = cursor.fetchone()
            if not college:
                raise RuntimeError("College not found")

            raw_name = cli_college_name or clean_text(college.get("name") or "")
            if not raw_name:
                raise RuntimeError("College name is empty")

            parsed = parse_college_input(
                raw_name,
                clean_text(college.get("city") or ""),
                clean_text(college.get("state") or ""),
                clean_text(college.get("pincode") or ""),
            )
            college_name = parsed["display_name"]
            search_name = parsed["search_name"]
            entered_city = parsed["city"]
            entered_state = parsed["state"]
            entered_pincode = parsed["pincode"]

            log(
                f"Scraping college_id={college_id} name={college_name} "
                f"search={search_name} city={entered_city} state={entered_state} pincode={entered_pincode}"
            )

            if entered_city or entered_state or entered_pincode:
                cursor.execute(
                    "UPDATE colleges SET city=COALESCE(NULLIF(%s,''), city), state=COALESCE(NULLIF(%s,''), state), pincode=COALESCE(NULLIF(%s,''), pincode) WHERE id=%s",
                    (entered_city, entered_state, entered_pincode, college_id),
                )
                conn.commit()

            saved_website = college.get("website") or ""
            pages = []
            website = ""
            source_note = "official"

            if saved_website and allowed_url(saved_website) and not is_directory_url(saved_website):
                try:
                    html, final_url = fetch_with_retry(saved_website)
                    if page_has_college_identity(html, search_name, college_name, entered_city, entered_state):
                        website = final_url
                        pages = collect_pages(final_url, entered_city, entered_state)
                        source_note = "saved official website"
                except Exception as exc:
                    log(f"Saved website unusable: {exc}")

            if not pages:
                pages, website, source_note = discover_college_pages(parsed, config)

            details = extract_details(pages, college_name)
            final_city = entered_city or details["city"]
            final_state = entered_state or details["state"]
            final_pincode = entered_pincode or details["pincode"]
            try:
                validate_scraped_details(details, college_name, final_city, final_state, search_name)
            except Exception as warn:
                log(f"Validation warning college_id={college_id}: {warn}")
            latitude, longitude = geocode(college_name, details["address"], final_city, final_state, config)
            details["city"] = final_city
            details["state"] = final_state
            details["pincode"] = final_pincode
            details["latitude"] = latitude
            details["longitude"] = longitude

            important_payload = json.loads(details["important_links"] or "{}")
            pdf_payload = json.loads(details["pdf_links"] or "[]")
            if pdf_payload:
                important_payload["downloads"] = {
                    "title": "Prospectus / Brochure / Fee PDFs",
                    "url": pdf_payload[0]["url"],
                    "items": pdf_payload,
                }
            details["important_links"] = json.dumps(important_payload, ensure_ascii=False)

            description_source = details.get("about_college") or details.get("best_description") or details.get("meta_description")
            if has_meaningful_value(description_source):
                details["short_description"] = summary(description_source, 300)
            details["description"] = build_evidence_sections(details)
            details["long_description"] = details["description"]

            for social_key, social_value in details["social"].items():
                if has_meaningful_value(social_value):
                    details[social_key] = social_value

            seo_title, seo_description, seo_keywords, seo_body = seo_content(college_name, website, details)
            seo_slug = build_seo_slug(college_name, final_city, final_state)
            if has_meaningful_value(seo_slug):
                cursor.execute(
                    "SELECT id FROM colleges WHERE slug=%s AND id<>%s LIMIT 1",
                    (seo_slug, college_id),
                )
                if cursor.fetchone():
                    seo_slug = ""
            else:
                seo_slug = ""

            payload = build_update_payload(
                college_name,
                website,
                details,
                seo_title if has_meaningful_value(seo_title) else "",
                seo_description,
                seo_keywords,
                seo_body,
                seo_slug,
                final_city,
                final_state,
                final_pincode,
            )

            data_fields = [
                "address", "phone", "email", "courses", "fees", "facilities",
                "admission_process", "placement_info", "about_college",
            ]
            found_fields = sum(1 for key in data_fields if has_meaningful_value(payload.get(key)))
            if not has_meaningful_value(website) and found_fields < 1:
                raise RuntimeError(
                    "Not enough college information found. Try: College Name, City, State"
                )

            save_college_updates(cursor, college_id, payload)
            conn.commit()
            log(f"Completed college_id={college_id} name={college_name} source={source_note} website={website}")
            print("Data Saved")
    except Exception as exc:
        conn.rollback()
        with conn.cursor() as cursor:
            mark_status(cursor, college_id, "failed", str(exc)[:1000])
            conn.commit()
        log(f"Failed college_id={college_id}: {exc}")
        print("Scraping failed:", exc)
        raise SystemExit(1)
    finally:
        conn.close()


if __name__ == "__main__":
    main()
