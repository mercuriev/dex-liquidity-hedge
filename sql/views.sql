CREATE VIEW TradesLastHour AS
SELECT ROUND(`data`->"$.p", 2) AS price, ROUND(`data`->"$.q", 5) AS quantity, `data`->"$.T" AS timestamp
FROM trade
WHERE timestamp > NOW() - INTERVAL 1 HOUR
ORDER BY timestamp;

CREATE VIEW Yesterday24Hours AS
SELECT ROUND(`data`->"$.p", 2) AS price, ROUND(`data`->"$.q", 5) AS quantity, `data`->"$.T" AS timestamp
FROM trade
WHERE DATE(timestamp) = DATE(NOW() - INTERVAL 1 DAY)
ORDER BY timestamp;
