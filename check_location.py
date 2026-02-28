import urllib.request, json, csv

BASE  = "https://api.data.gov.my/weather/forecast/"
TYPES = {
    "St": "State",
    "Ds": "District",
    "Tn": "Town",
    "Dv": "Division",
    "Rc": "Recreational",
}

# Hardcoded id→state. Never read from CSV. Add new entries here when new IDs appear.
STATE_MAP = {
    # States map to themselves
    "St001": "Perlis",          "St002": "Kedah",           "St003": "Pulau Pinang",
    "St004": "Perak",           "St005": "Kelantan",        "St006": "Terengganu",
    "St007": "Pahang",          "St008": "Selangor",        "St009": "WP Kuala Lumpur",
    "St010": "WP Putrajaya",    "St011": "Negeri Sembilan", "St012": "Melaka",
    "St013": "Johor",           "St501": "Sarawak",         "St502": "Sabah",
    "St503": "WP Labuan",
    # Districts
    "Ds001": "Kedah",           "Ds002": "Perlis",          "Ds003": "Kedah",
    "Ds004": "Kedah",           "Ds005": "Kedah",           "Ds006": "Kedah",
    "Ds007": "Kedah",           "Ds008": "Kedah",           "Ds009": "Kedah",
    "Ds010": "Kedah",           "Ds011": "Pulau Pinang",    "Ds012": "Pulau Pinang",
    "Ds013": "Pulau Pinang",    "Ds014": "Pulau Pinang",    "Ds015": "Kedah",
    "Ds016": "Kedah",           "Ds017": "Pulau Pinang",    "Ds018": "Kedah",
    "Ds019": "Perak",           "Ds020": "Perak",           "Ds021": "Perak",
    "Ds022": "Kelantan",        "Ds023": "Kelantan",        "Ds024": "Kelantan",
    "Ds025": "Kelantan",        "Ds026": "Perak",           "Ds027": "Kelantan",
    "Ds028": "Kelantan",        "Ds029": "Perak",           "Ds030": "Kelantan",
    "Ds031": "Kelantan",        "Ds032": "Perak",           "Ds033": "Perak",
    "Ds034": "Kelantan",        "Ds035": "Perak",           "Ds036": "Perak",
    "Ds037": "Terengganu",      "Ds038": "Pahang",          "Ds039": "Kelantan",
    "Ds040": "Perak",           "Ds041": "Perak",           "Ds042": "Terengganu",
    "Ds043": "Selangor",        "Ds044": "Pahang",          "Ds045": "Perak",
    "Ds046": "Terengganu",      "Ds047": "Terengganu",      "Ds048": "Terengganu",
    "Ds049": "Selangor",        "Ds050": "Pahang",          "Ds051": "Selangor",
    "Ds052": "Terengganu",      "Ds053": "Pahang",          "Ds054": "Selangor",
    "Ds055": "Selangor",        "Ds056": "Terengganu",      "Ds057": "Selangor",
    "Ds058": "WP Kuala Lumpur", "Ds059": "Pahang",          "Ds060": "Selangor",
    "Ds061": "Pahang",          "Ds062": "WP Putrajaya",    "Ds063": "Selangor",
    "Ds064": "Selangor",        "Ds065": "Terengganu",      "Ds066": "Pahang",
    "Ds067": "Negeri Sembilan", "Ds068": "Negeri Sembilan", "Ds069": "Pahang",
    "Ds070": "Negeri Sembilan", "Ds071": "Pahang",          "Ds072": "Negeri Sembilan",
    # Towns
    "Tn001": "Perlis",          "Tn002": "Kedah",           "Tn003": "Kedah",
    "Tn004": "Kedah",           "Tn005": "Kedah",           "Tn006": "Kedah",
    "Tn007": "Kedah",           "Tn008": "Kedah",           "Tn009": "Pulau Pinang",
    "Tn010": "Pulau Pinang",    "Tn011": "Kedah",           "Tn012": "Pulau Pinang",
    "Tn013": "Pulau Pinang",    "Tn014": "Pulau Pinang",    "Tn015": "Pulau Pinang",
    "Tn016": "Pulau Pinang",    "Tn017": "Pulau Pinang",    "Tn018": "Pulau Pinang",
    "Tn019": "Pulau Pinang",    "Tn020": "Kedah",           "Tn021": "Kedah",
    "Tn022": "Pulau Pinang",    "Tn023": "Kedah",           "Tn024": "Perak",
    "Tn025": "Perak",           "Tn026": "Perak",           "Tn027": "Perak",
    "Tn028": "Perak",           "Tn029": "Perak",           "Tn030": "Kelantan",
    "Tn031": "Kelantan",        "Tn032": "Kelantan",        "Tn033": "Kelantan",
    "Tn034": "Kelantan",        "Tn035": "Perak",           "Tn036": "Perak",
    "Tn037": "Kelantan",        "Tn038": "Kelantan",        "Tn039": "Kelantan",
    "Tn040": "Perak",           "Tn041": "Perak",           "Tn042": "Kelantan",
    "Tn043": "Perak",           "Tn044": "Perak",           "Tn045": "Kelantan",
    "Tn046": "Perak",           "Tn047": "Terengganu",      "Tn048": "Terengganu",
    "Tn049": "Perak",           "Tn050": "Perak",           "Tn051": "Perak",
    "Tn052": "Perak",           "Tn053": "Kelantan",        "Tn054": "Selangor",
    "Tn055": "Terengganu",      "Tn056": "Terengganu",      "Tn057": "Perak",
    "Tn058": "Terengganu",      "Tn059": "Terengganu",      "Tn060": "Pahang",
    "Tn061": "Selangor",        "Tn062": "Pahang",          "Tn063": "Selangor",
    "Tn064": "Selangor",        "Tn065": "Selangor",        "Tn066": "Selangor",
    "Tn067": "WP Kuala Lumpur", "Tn068": "Pahang",          "Tn069": "Selangor",
    "Tn070": "Selangor",        "Tn071": "WP Kuala Lumpur", "Tn072": "Pahang",
    "Tn073": "Selangor",        "Tn074": "Selangor",        "Tn075": "Selangor",
    "Tn076": "WP Kuala Lumpur", "Tn077": "WP Kuala Lumpur", "Tn078": "Selangor",
    "Tn079": "WP Kuala Lumpur",
    # Divisions (Sarawak/Sabah/Labuan)
    "Dv501": "Sarawak",  "Dv502": "Sarawak",  "Dv503": "Sarawak",  "Dv504": "Sarawak",
    "Dv505": "Sarawak",  "Dv506": "Sarawak",  "Dv507": "Sarawak",  "Dv508": "Sarawak",
    "Dv509": "Sarawak",  "Dv510": "Sarawak",  "Dv511": "Sarawak",  "Dv512": "Sarawak",
    "Dv513": "WP Labuan","Dv514": "Sabah",    "Dv515": "Sabah",    "Dv516": "Sabah",
    "Dv517": "Sabah",    "Dv518": "Sabah",
    # Recreational
    "Rc001": "Kedah",           "Rc002": "Pulau Pinang",    "Rc003": "Pulau Pinang",
    "Rc004": "Pulau Pinang",    "Rc005": "Perak",           "Rc006": "Perak",
    "Rc007": "Terengganu",      "Rc008": "Pahang",          "Rc009": "Terengganu",
    "Rc010": "Kelantan",        "Rc011": "Terengganu",      "Rc012": "Terengganu",
    "Rc013": "Pahang",          "Rc014": "Pahang",          "Rc015": "Terengganu",
    "Rc016": "Pahang",          "Rc017": "Pahang",          "Rc018": "Pahang",
    "Rc019": "Pahang",          "Rc020": "Terengganu",      "Rc021": "Selangor",
    "Rc022": "Terengganu",      "Rc023": "Pahang",          "Rc024": "Pahang",
    "Rc025": "Johor",           "Rc026": "Johor",
    "Rc501": "Sabah",           "Rc502": "Sabah",
}

# Infer state for IDs not in STATE_MAP (new additions from API)
KNOWN_STATES = [
    "Perlis", "Kedah", "Pulau Pinang", "Perak", "Kelantan", "Terengganu",
    "Pahang", "Selangor", "WP Kuala Lumpur", "WP Putrajaya", "Negeri Sembilan",
    "Melaka", "Johor", "Sarawak", "Sabah", "WP Labuan",
]

def infer_state(loc_id, name):
    # Check if a state name appears in the location name
    for state in sorted(KNOWN_STATES, key=len, reverse=True):
        if state.lower() in name.lower():
            return state
    # Infer from ID prefix ranges (Dv = Sarawak/Sabah/Labuan, St = itself)
    prefix = loc_id[:2]
    if prefix == "Dv":
        return "Sarawak"  # best guess for unknown divisions
    return ""

# ── Fetch all locations from API with pagination ─────────────────────────────
rows       = []
unresolved = []

for prefix, label in TYPES.items():
    seen   = {}
    offset = 0
    limit  = 1000

    while True:
        url  = f"{BASE}?contains={prefix}@location__location_id&limit={limit}&offset={offset}"
        data = json.loads(urllib.request.urlopen(url).read())
        if not data:
            break
        new_ids = 0
        for row in data:
            loc = row["location"]
            if loc["location_id"] not in seen:
                seen[loc["location_id"]] = loc["location_name"]
                new_ids += 1
        if new_ids == 0:  # all IDs on this page already seen — we have all unique locations
            break
        offset += limit

    for lid, name in sorted(seen.items()):
        state = STATE_MAP.get(lid) or infer_state(lid, name)
        if not state:
            unresolved.append((lid, name))
        rows.append([label, lid, name, state])

    print(f"{label}: {len(seen)} locations fetched")

# ── Write CSV ────────────────────────────────────────────────────────────────
with open("locations.csv", "w", newline="", encoding="utf-8") as f:
    writer = csv.writer(f)
    writer.writerow(["type", "id", "name", "state"])
    writer.writerows(rows)

print(f"\nSaved {len(rows)} rows to locations.csv")

if unresolved:
    print(f"\nWARNING: could not infer state for {len(unresolved)} IDs — add them to STATE_MAP:")
    for lid, name in unresolved:
        print(f'    "{lid}": "",  # {name}')
