# National Weather System Website, Malaysia
An NWS-like website as an idea to improve visibility of real-time and historical weather data in Malaysia. Focus on fast-to-navigate website, data-heavy information, and priotizing alerts/releases. Inspired by NWS Tulsa and the fact that I almost had a heatstroke on a day that is already warned to be really hot but I can only know that through being extremely online instead of the warning being integrated to the default weather app, SMS, anything but having to open facebook.  
To run this: php -S localhost:8000  
Update (2/18/2026): 
1. Split php and CSS in separate files
2. Add Damansara
3. ramalancuacasignifikan.jpg is an actual placeholder file that is updated. Will keep using this
4. Ramalan Cuaca Khas pdf file also exist. Will monitor in next iterations to see if the link behaves similarly as 3.
5. Add weather emojis for visual clarity
6. Filter advisories just to Klang Valley regions to narrow down data 
7. Next steps: Attempt to reduce scrolling by introducing AI summary tools like tesseract OCR or I will just build one myself; Create a KV map like NWS main home page to show everything with no scrolling, then can head to specific region/city to check in detail

Update (1/25/2026): 
1. Significantly altered the code to quickly display data. Noticed that data is repetitive (2 rows per forecast day), warnings are not tied to a region/location
2. The API does NOT include a "significant weather" post which seems to be exclusively posted through web and social media? Image is scrapped and may be non-functional when URL is changed
3. Next steps: Specify Klang Valley towns; Add weather icons next to forecast; group all advisories/warnings into 1 tabbed box a-la NWS
