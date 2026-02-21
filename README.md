# National Weather System Website, Malaysia
An NWS-like website as an idea to improve visibility of real-time and historical weather data in Malaysia. Focus on fast-to-navigate website, data-heavy information, and priotizing alerts/releases. Inspired by NWS Tulsa and the fact that I almost had a heatstroke on a day that is already warned to be really hot but I can only know that through being extremely online instead of the warning being integrated to the default weather app, SMS, anything but having to open facebook.  
Update (2/21/2026): 
1. tesseract OCR to extract text from image and turn into a warning box on top
2. attempt to make map: failed. NWS has a pre-configured image that is already mapped with weather, cropped to its divisions, and turn into png files that is clickable. a lot of work for just one person
3. Next steps: biggest issue of METmalaysia is data mapping; warnings are not mapped, everyone gets warnings for a weather in Sarawak; start storing historical data? more data work next; create issues to get help; remove earthquake, useless to KV

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
