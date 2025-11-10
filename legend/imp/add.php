<?php
/**
 * Address Data Provider - Enhanced Version
 * Provides US addresses for testing and development
 */

class AddressProvider {
    private static $addresses = [
        // Washington DC
        ["numd" => "1600", "address1" => "Pennsylvania Ave NW", "city" => "Washington", "state" => "DC", "zip" => "20500"],
        
        // New York
        ["numd" => "350", "address1" => "5th Ave", "city" => "New York", "state" => "NY", "zip" => "10118"],
        ["numd" => "345", "address1" => "Park Ave S", "city" => "New York", "state" => "NY", "zip" => "10010"],
        
        // California
        ["numd" => "1", "address1" => "Infinite Loop", "city" => "Cupertino", "state" => "CA", "zip" => "95014"],
        ["numd" => "221B", "address1" => "Baker Street", "city" => "Los Angeles", "state" => "CA", "zip" => "90001"],
        ["numd" => "600", "address1" => "Montgomery St", "city" => "San Francisco", "state" => "CA", "zip" => "94111"],
        ["numd" => "200", "address1" => "N Broadway", "city" => "Los Angeles", "state" => "CA", "zip" => "90012"],
        ["numd" => "333", "address1" => "Sunset Blvd", "city" => "Los Angeles", "state" => "CA", "zip" => "90046"],
        ["numd" => "789", "address1" => "Capitol St", "city" => "Sacramento", "state" => "CA", "zip" => "95814"],
        
        // Illinois
        ["numd" => "401", "address1" => "N Michigan Ave", "city" => "Chicago", "state" => "IL", "zip" => "60611"],
        
        // Indiana
        ["numd" => "500", "address1" => "S Capitol Ave", "city" => "Indianapolis", "state" => "IN", "zip" => "46204"],
        
        // Florida
        ["numd" => "600", "address1" => "Biscayne Blvd", "city" => "Miami", "state" => "FL", "zip" => "33132"],
        ["numd" => "121", "address1" => "Ocean Dr", "city" => "Miami Beach", "state" => "FL", "zip" => "33139"],
        ["numd" => "321", "address1" => "Franklin St", "city" => "Jacksonville", "state" => "FL", "zip" => "32202"],
        
        // Texas
        ["numd" => "700", "address1" => "Louisiana St", "city" => "Houston", "state" => "TX", "zip" => "77002"],
        ["numd" => "1100", "address1" => "Congress Ave", "city" => "Austin", "state" => "TX", "zip" => "78701"],
        ["numd" => "123", "address1" => "Main St", "city" => "Dallas", "state" => "TX", "zip" => "75201"],
        
        // Colorado
        ["numd" => "1601", "address1" => "Bryant St", "city" => "Denver", "state" => "CO", "zip" => "80204"],
        
        // Pennsylvania
        ["numd" => "1500", "address1" => "Market St", "city" => "Philadelphia", "state" => "PA", "zip" => "19102"],
        
        // Georgia
        ["numd" => "100", "address1" => "Peachtree St NE", "city" => "Atlanta", "state" => "GA", "zip" => "30303"],
        
        // Michigan
        ["numd" => "500", "address1" => "Woodward Ave", "city" => "Detroit", "state" => "MI", "zip" => "48226"],
        
        // Massachusetts
        ["numd" => "200", "address1" => "Boylston St", "city" => "Boston", "state" => "MA", "zip" => "02116"],
        
        // Virginia
        ["numd" => "800", "address1" => "N Glebe Rd", "city" => "Arlington", "state" => "VA", "zip" => "22203"],
        
        // Nevada
        ["numd" => "3500", "address1" => "S Las Vegas Blvd", "city" => "Las Vegas", "state" => "NV", "zip" => "89109"],
        
        // Maine
        ["numd" => "600", "address1" => "Congress St", "city" => "Portland", "state" => "ME", "zip" => "04101"],
        
        // North Carolina
        ["numd" => "987", "address1" => "Elm St", "city" => "Charlotte", "state" => "NC", "zip" => "28202"],
        
        // Arizona
        ["numd" => "765", "address1" => "Central Ave", "city" => "Phoenix", "state" => "AZ", "zip" => "85004"],
        
        // Tennessee
        ["numd" => "321", "address1" => "Broad St", "city" => "Nashville", "state" => "TN", "zip" => "37203"],
        
        // Ohio
        ["numd" => "444", "address1" => "Oak St", "city" => "Columbus", "state" => "OH", "zip" => "43215"],
        
        // Washington
        ["numd" => "555", "address1" => "Pine St", "city" => "Seattle", "state" => "WA", "zip" => "98101"],
        
        // Minnesota
        ["numd" => "777", "address1" => "Maple Ave", "city" => "Minneapolis", "state" => "MN", "zip" => "55402"],
        
        // Missouri
        ["numd" => "888", "address1" => "River St", "city" => "St. Louis", "state" => "MO", "zip" => "63101"],
        ["numd" => "999", "address1" => "Cedar Rd", "city" => "Kansas City", "state" => "MO", "zip" => "64106"],
        
        // Louisiana
        ["numd" => "111", "address1" => "Hickory St", "city" => "New Orleans", "state" => "LA", "zip" => "70130"],
        
        // Wisconsin
        ["numd" => "222", "address1" => "Sycamore Ln", "city" => "Milwaukee", "state" => "WI", "zip" => "53202"],
        
        // Kentucky
        ["numd" => "456", "address1" => "Jefferson Ave", "city" => "Louisville", "state" => "KY", "zip" => "40202"],
        
        // Oregon
        ["numd" => "654", "address1" => "Union St", "city" => "Portland", "state" => "OR", "zip" => "97204"],
        
        // Maryland
        ["numd" => "852", "address1" => "Lexington Ave", "city" => "Baltimore", "state" => "MD", "zip" => "21201"],
        
        // South Carolina
        ["numd" => "963", "address1" => "King St", "city" => "Charleston", "state" => "SC", "zip" => "29401"],
    ];
    
    /**
     * Get a random address
     * @return array Random address data
     */
    public static function getRandomAddress(): array {
        return self::$addresses[array_rand(self::$addresses)];
    }
    
    /**
     * Get address by state
     * @param string $state State abbreviation (e.g., 'CA', 'NY')
     * @return array|null Address data or null if not found
     */
    public static function getAddressByState(string $state): ?array {
        $filtered = array_filter(self::$addresses, function($addr) use ($state) {
            return $addr['state'] === strtoupper($state);
        });
        return !empty($filtered) ? $filtered[array_rand($filtered)] : null;
    }
    
    /**
     * Get address by city
     * @param string $city City name
     * @return array|null Address data or null if not found
     */
    public static function getAddressByCity(string $city): ?array {
        $filtered = array_filter(self::$addresses, function($addr) use ($city) {
            return strcasecmp($addr['city'], $city) === 0;
        });
        return !empty($filtered) ? $filtered[array_rand($filtered)] : null;
    }
    
    /**
     * Get formatted full address
     * @param array $address Address data
     * @return string Formatted address
     */
    public static function formatAddress(array $address): string {
        return sprintf(
            "%s %s, %s, %s %s",
            $address['numd'],
            $address['address1'],
            $address['city'],
            $address['state'],
            $address['zip']
        );
    }
    
    /**
     * Get all addresses
     * @return array All addresses
     */
    public static function getAllAddresses(): array {
        return self::$addresses;
    }
    
    /**
     * Get total count
     * @return int Number of addresses
     */
    public static function count(): int {
        return count(self::$addresses);
    }
}

// Backward compatibility - maintain original variable structure
$randomAddress = AddressProvider::getRandomAddress();
$addresses = AddressProvider::getAllAddresses();
?>
